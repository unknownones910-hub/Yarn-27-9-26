<?php
/**
 * Navigation data.
 *
 * Categories are grouped by `kind` so the header, mega-menu, drawer and
 * footer all read from one place. Cached per request.
 */
declare(strict_types=1);

function nav_categories(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $grouped = ['craft' => [], 'fiber' => [], 'weight' => [], 'other' => []];

    try {
        $rows = db_all(
            'SELECT id, name, slug, kind, icon, image, description FROM categories
             WHERE is_active = 1
             ORDER BY kind, sort_order, name'
        );
        foreach ($rows as $r) {
            $grouped[$r['kind']][] = $r;
        }
    } catch (Throwable $e) {
        error_log('nav_categories: ' . $e->getMessage());
    }

    return $cache = $grouped;
}

/** Top-level links shown in the desktop bar. */
/**
 * The one and only header nav.
 *
 * Craft and fibre categories deliberately do NOT live here any more — they are
 * the sidebar filters and the mobile drawer's "Shop by…" groups. Repeating them
 * in the header made the same words appear twice on one screen and read as a
 * broken filter.
 */
function nav_primary(): array
{
    $items = [
        ['label' => 'Home',        'url' => url('')],
        ['label' => 'Shop',        'url' => url('shop.php')],
        ['label' => 'Yarn Guide',  'url' => url('yarn-guide.php')],
        ['label' => 'Sale',        'url' => url('category/sale'), 'class' => 'sale-link'],
        ['label' => 'About Us',    'url' => url('about.php')],
        ['label' => 'FAQ',         'url' => url('faq.php')],
        ['label' => 'Contact',     'url' => url('contact.php')],
        ['label' => 'Track Order', 'url' => url('track-order.php')],
    ];

    if (setting_bool('feat_wholesale', true)) {
        $items[] = ['label' => 'Wholesale', 'url' => url('wholesale.php')];
    }

    return $items;
}

/** Marks the active item in the header nav. */
function nav_is_current(string $href): bool
{
    $here = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $there = strtok($href, '?');
    if ($there === '/' || $there === url('')) {
        return $here === '/' || $here === '/index.php';
    }
    return $here === $there;
}

/** Number of items in the cart. Cart lib arrives in Phase 3. */
function nav_cart_count(): int
{
    if (function_exists('cart_count')) {
        return cart_count();
    }
    return 0;
}

/** WhatsApp click-to-chat link built from the stored number. */
function whatsapp_link(string $prefill = ''): string
{
    $num = preg_replace('/\D+/', '', setting('store_whatsapp')) ?? '';
    if ($num === '') {
        return '';
    }
    $url = 'https://wa.me/' . $num;
    if ($prefill !== '') {
        $url .= '?text=' . rawurlencode($prefill);
    }
    return $url;
}
