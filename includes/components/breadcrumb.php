<?php
/**
 * Breadcrumbs — the visible trail and the JSON-LD that mirrors it.
 *
 * Both take the same array so they can never disagree, which is exactly the
 * failure Google flags: structured data describing a trail the page does not
 * actually show.
 *
 *   $crumbs = [['Home', url('')], ['Shop', url('shop.php')], ['Merino DK', null]];
 *
 * The last entry has a null URL — it is the current page.
 *
 * Loaded by bootstrap.php, because pages build $jsonLd before header.php runs.
 */
declare(strict_types=1);

/**
 * A schema.org BreadcrumbList for $jsonLd.
 *
 * @param array<int,array{0:string,1:?string}> $crumbs
 * @return array<string,mixed>
 */
function breadcrumb_ld(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => $c) {
        $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0]];
        if (($c[1] ?? null) !== null) {
            $item['item'] = abs_url($c[1]);
        }
        $items[] = $item;
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

/**
 * The visible trail.
 *
 * @param array<int,array{0:string,1:?string}> $crumbs
 */
function breadcrumb_nav(array $crumbs): string
{
    if (count($crumbs) < 2) {
        return '';   // "Home" on its own is noise
    }

    $li = '';
    foreach ($crumbs as $c) {
        $li .= '<li>' . (($c[1] ?? null) !== null
            ? '<a href="' . e($c[1]) . '">' . e($c[0]) . '</a>'
            : '<span aria-current="page">' . e($c[0]) . '</span>') . '</li>';
    }

    return '<nav class="breadcrumb" aria-label="Breadcrumb"><ol>' . $li . '</ol></nav>';
}
