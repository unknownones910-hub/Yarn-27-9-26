<?php
/**
 * Catalog data layer.
 *
 * Everything the shop grid, category pages, search and the PDP need. All list
 * queries are paginated and avoid N+1 by fetching variants for a whole page of
 * products in one `WHERE product_id IN (...)`.
 */
declare(strict_types=1);

const CATALOG_PER_PAGE = 24;

// ------------------------------------------------------------- filtering --

/**
 * Read filters out of the query string once, so shop / search / category all
 * behave identically and the URL stays the single source of truth.
 */
function catalog_filters_from_request(): array
{
    $multi = static function (string $key): array {
        $raw = $_GET[$key] ?? [];
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $v = mb_substr(trim((string) $v), 0, 40);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    };

    return [
        'q'         => get_str('q', 80),
        'category'  => get_str('category', 120),
        'collection'=> get_str('collection', 120),
        'craft'     => $multi('craft'),
        'fiber'     => $multi('fiber'),
        'weight'    => $multi('weight'),
        'color'     => $multi('color'),
        'brand'     => $multi('brand'),
        'price_min' => get_int('price_min', 0),
        'price_max' => get_int('price_max', 0),
        'in_stock'  => get_str('in_stock') === '1',
        'on_sale'   => get_str('on_sale') === '1',
        'rated'     => get_str('rated') === '1',
        'sort'      => get_str('sort', 20),
    ];
}

/** Are any real filters active? Drives the "clear all" chip and noindex. */
function catalog_has_filters(array $f): bool
{
    return $f['craft'] || $f['fiber'] || $f['weight'] || $f['color'] || $f['brand']
        || $f['price_min'] > 0 || $f['price_max'] > 0
        || $f['in_stock'] || $f['on_sale'] || $f['rated'];
}

/**
 * Build the shared WHERE fragment + params for a filter set.
 * @return array{0:string,1:array}
 */
function catalog_where(array $f): array
{
    $where  = ['p.is_active = 1'];
    $params = [];

    // Category page (any taxonomy: craft, fibre, weight or other).
    if ($f['category'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM product_categories pc
                            JOIN categories c ON c.id = pc.category_id
                            WHERE pc.product_id = p.id AND c.slug = ?)';
        $params[] = $f['category'];
    }

    if (!empty($f['collection'])) {
        $where[] = 'EXISTS (SELECT 1 FROM collection_products cp
                            JOIN collections col ON col.id = cp.collection_id
                            WHERE cp.product_id = p.id AND col.slug = ? AND col.is_active = 1)';
        $params[] = $f['collection'];
    }

    // Craft / fibre come from the taxonomy; weight is authoritative on the
    // product row itself, so it filters the enum directly.
    foreach (['craft', 'fiber'] as $kind) {
        if (!empty($f[$kind])) {
            $in = db_in($f[$kind]);
            $where[] = "EXISTS (SELECT 1 FROM product_categories pc
                                JOIN categories c ON c.id = pc.category_id
                                WHERE pc.product_id = p.id AND c.kind = ? AND c.slug IN ($in))";
            $params[] = $kind;
            foreach ($f[$kind] as $slug) {
                $params[] = $slug;
            }
        }
    }

    if (!empty($f['weight'])) {
        $valid = array_values(array_intersect($f['weight'], array_keys(yarn_weights())));
        if ($valid) {
            $where[] = 'p.yarn_weight IN (' . db_in($valid) . ')';
            foreach ($valid as $w) {
                $params[] = $w;
            }
        }
    }

    if (!empty($f['color'])) {
        $where[] = 'EXISTS (SELECT 1 FROM product_variants v
                            WHERE v.product_id = p.id AND v.is_active = 1
                              AND v.color_family IN (' . db_in($f['color']) . '))';
        foreach ($f['color'] as $c) {
            $params[] = $c;
        }
    }

    if (!empty($f['brand'])) {
        $where[] = 'p.brand IN (' . db_in($f['brand']) . ')';
        foreach ($f['brand'] as $b) {
            $params[] = $b;
        }
    }

    if ($f['price_min'] > 0) {
        $where[]  = 'p.price >= ?';
        $params[] = (float) $f['price_min'];
    }
    if ($f['price_max'] > 0) {
        $where[]  = 'p.price <= ?';
        $params[] = (float) $f['price_max'];
    }

    if ($f['in_stock']) {
        $where[] = 'EXISTS (SELECT 1 FROM product_variants v
                            WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0)';
    }
    if ($f['on_sale']) {
        $where[] = 'p.mrp > p.price';
    }
    if ($f['rated']) {
        $where[] = 'p.rating_avg >= 4';
    }

    // Search. FULLTEXT for real words, LIKE for 1-3 character fragments and
    // for SKU-ish queries that the fulltext index will not match.
    if ($f['q'] !== '') {
        if (mb_strlen($f['q']) >= 4 && preg_match('/^[\p{L}\p{N} ]+$/u', $f['q'])) {
            $where[]  = 'MATCH (p.name, p.subtitle, p.short_desc, p.fiber, p.brand) AGAINST (? IN BOOLEAN MODE)';
            $params[] = catalog_boolean_terms($f['q']);
        } else {
            $where[]  = '(p.name LIKE ? OR p.fiber LIKE ? OR p.brand LIKE ? OR p.sku_base LIKE ?)';
            $like     = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
    }

    return [implode("\n  AND ", $where), $params];
}

/** "milk cotton" -> "+milk* +cotton*" so every word must appear. */
function catalog_boolean_terms(string $q): string
{
    $words = preg_split('/\s+/u', trim($q)) ?: [];
    $out   = [];
    foreach ($words as $w) {
        $w = preg_replace('/[+\-><()~*"@]+/', '', $w) ?? '';
        if (mb_strlen($w) >= 2) {
            $out[] = '+' . $w . '*';
        }
    }
    return $out ? implode(' ', $out) : $q;
}

function catalog_order_by(string $sort, bool $searching = false): string
{
    return match ($sort) {
        'new'        => 'p.created_at DESC, p.id DESC',
        'price_asc'  => 'p.price ASC, p.id DESC',
        'price_desc' => 'p.price DESC, p.id DESC',
        'rating'     => 'p.rating_avg DESC, p.rating_count DESC, p.id DESC',
        'popular'    => 'p.sold_count DESC, p.rating_avg DESC, p.id DESC',
        default      => $searching
            ? 'p.sold_count DESC, p.rating_avg DESC, p.id DESC'
            : 'p.is_featured DESC, p.sort_order ASC, p.created_at DESC, p.id DESC',
    };
}

function catalog_sort_options(): array
{
    return [
        ''           => 'Relevance',
        'new'        => 'Newest first',
        'popular'    => 'Bestselling',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'rating'     => 'Top rated',
    ];
}

/**
 * Run a catalog query.
 * @return array{rows:array,total:int,pages:int,page:int,per_page:int}
 */
/**
 * A schema.org ItemList for a listing page, in the order shown.
 *
 * Only emit this on an indexable listing — describing a filtered permutation
 * that carries `noindex` tells Google about a page it has been asked to ignore.
 *
 * @param array<int,array<string,mixed>> $rows rows from catalog_query()
 * @return array<string,mixed>
 */
function catalog_item_list_ld(string $name, array $rows): array
{
    $items = [];
    foreach (array_values($rows) as $i => $p) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'url'      => abs_url('product/' . $p['slug']),
            'name'     => (string) $p['name'],
        ];
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => $name,
        'numberOfItems'   => count($items),
        'itemListElement' => $items,
    ];
}

function catalog_query(array $f, int $page = 1, int $perPage = CATALOG_PER_PAGE): array
{
    [$whereSql, $params] = catalog_where($f);

    $total = (int) db_val("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    $order = catalog_order_by($f['sort'], $f['q'] !== '');

    // LIMIT/OFFSET are cast ints, never user strings.
    $rows = db_all(
        "SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight, p.ply,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count,
                p.is_new, p.is_featured, p.sold_count, p.created_at
         FROM products p
         WHERE {$whereSql}
         ORDER BY {$order}
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    catalog_attach_variants($rows);

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages,
            'page' => $page, 'per_page' => $perPage];
}

/**
 * Attach shade + stock data to a page of products in ONE query.
 * Without this the grid would fire a query per card.
 */
function catalog_attach_variants(array &$rows): void
{
    if (!$rows) {
        return;
    }

    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $variants = db_all(
        'SELECT id, product_id, sku, shade_name, color_hex, color_family, image,
                price_override, stock
         FROM product_variants
         WHERE is_active = 1 AND product_id IN (' . db_in($ids) . ')
         ORDER BY product_id, sort_order, id',
        $ids
    );

    $byProduct = [];
    foreach ($variants as $v) {
        $byProduct[(int) $v['product_id']][] = $v;
    }

    foreach ($rows as &$r) {
        $vs = $byProduct[(int) $r['id']] ?? [];
        $r['variants']    = $vs;
        $r['shade_count'] = count($vs);
        $r['stock_total'] = array_sum(array_map(static fn($v) => max(0, (int) $v['stock']), $vs));
        $r['in_stock']    = $r['stock_total'] > 0;
    }
    unset($r);
}

// ---------------------------------------------------------------- single --

function product_by_slug(string $slug): ?array
{
    return db_row('SELECT * FROM products WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug]);
}

function product_variants(int $productId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM product_variants WHERE product_id = ?'
         . ($activeOnly ? ' AND is_active = 1' : '')
         . ' ORDER BY sort_order, id';
    return db_all($sql, [$productId]);
}

function product_images(int $productId): array
{
    return db_all(
        'SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id',
        [$productId]
    );
}

/** Primary category, for the breadcrumb. */
function product_primary_category(array $product): ?array
{
    if (empty($product['category_id'])) {
        return null;
    }
    return db_row('SELECT id, name, slug FROM categories WHERE id = ? LIMIT 1',
                  [(int) $product['category_id']]);
}

function product_category_slugs(int $productId): array
{
    return db_col(
        'SELECT c.slug FROM product_categories pc
         JOIN categories c ON c.id = pc.category_id
         WHERE pc.product_id = ?',
        [$productId]
    );
}

/** A variant's effective price — override wins, else the product price. */
function variant_price(array $product, ?array $variant): float
{
    if ($variant && $variant['price_override'] !== null && $variant['price_override'] !== '') {
        return (float) $variant['price_override'];
    }
    return (float) $product['price'];
}

/**
 * Similar yarns: same weight first, then same fibre family, never itself.
 */
function related_products(array $product, int $limit = 4): array
{
    $rows = db_all(
        'SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count,
                p.is_new
         FROM products p
         WHERE p.is_active = 1 AND p.id <> ?
         ORDER BY (p.yarn_weight = ?) DESC, (p.fiber = ?) DESC, p.sold_count DESC, RAND()
         LIMIT ' . (int) $limit,
        [(int) $product['id'], (string) $product['yarn_weight'], (string) $product['fiber']]
    );
    catalog_attach_variants($rows);
    return $rows;
}

/** Hooks, needles and notions to pair with a yarn. */
function accessory_products(int $limit = 4, int $excludeId = 0): array
{
    $rows = db_all(
        'SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count, p.is_new
         FROM products p
         JOIN product_categories pc ON pc.product_id = p.id
         JOIN categories c ON c.id = pc.category_id
         WHERE p.is_active = 1 AND c.slug = ? AND p.id <> ?
         GROUP BY p.id
         ORDER BY p.sold_count DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['tools-and-accessories', $excludeId]
    );
    catalog_attach_variants($rows);
    return $rows;
}

function featured_products(int $limit = 8): array
{
    $rows = db_all(
        'SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count, p.is_new
         FROM products p
         WHERE p.is_active = 1 AND p.is_featured = 1
         ORDER BY p.sort_order, p.id DESC
         LIMIT ' . (int) $limit
    );
    catalog_attach_variants($rows);
    return $rows;
}

function newest_products(int $limit = 8): array
{
    $rows = db_all(
        'SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count, p.is_new
         FROM products p
         WHERE p.is_active = 1
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ' . (int) $limit
    );
    catalog_attach_variants($rows);
    return $rows;
}

function category_by_slug(string $slug): ?array
{
    return db_row('SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug]);
}

function collection_by_slug(string $slug): ?array
{
    return db_row('SELECT * FROM collections WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug]);
}

/** Price bounds across the live catalog, for the price filter. */
function catalog_price_bounds(): array
{
    $row = db_row('SELECT MIN(price) AS lo, MAX(price) AS hi FROM products WHERE is_active = 1');
    return [
        'min' => (int) floor((float) ($row['lo'] ?? 0)),
        'max' => (int) ceil((float) ($row['hi'] ?? 1000)),
    ];
}

/** Distinct brands present in the catalog. */
function catalog_brands(): array
{
    return db_col(
        "SELECT DISTINCT brand FROM products
         WHERE is_active = 1 AND brand IS NOT NULL AND brand <> ''
         ORDER BY brand"
    );
}

/** Weight keys actually stocked, so the filter never offers an empty option. */
function catalog_weights_in_use(): array
{
    return db_col(
        'SELECT DISTINCT yarn_weight FROM products
         WHERE is_active = 1 AND yarn_weight IS NOT NULL'
    );
}

function catalog_color_families_in_use(): array
{
    return db_col(
        'SELECT DISTINCT v.color_family
         FROM product_variants v JOIN products p ON p.id = v.product_id
         WHERE v.is_active = 1 AND p.is_active = 1'
    );
}

// ------------------------------------------------------------- imagery --

/**
 * Best available image for a product card or the PDP gallery.
 * Order: variant photo → first product photo → tinted yarn-ball placeholder.
 */
function product_image_src(array $product, ?array $variant = null): string
{
    if ($variant && !empty($variant['image'])) {
        return upload_url($variant['image']);
    }
    if (!empty($product['image'])) {
        return upload_url($product['image']);
    }
    if (!empty($product['variants'][0]['image'])) {
        return upload_url($product['variants'][0]['image']);
    }

    $hex = $variant['color_hex']
        ?? ($product['variants'][0]['color_hex'] ?? '#D8C3A9');

    return yarn_ball_uri((string) $hex);
}

/** One-line spec under the product name: "100% Cotton · DK · 50g". */
function product_meta_line(array $p): string
{
    $bits = array_filter([
        $p['fiber'] ?? '',
        yarn_weight_label($p['yarn_weight'] ?? null),
        !empty($p['ball_weight_g']) ? $p['ball_weight_g'] . 'g' : '',
    ]);
    return implode(' · ', $bits);
}
