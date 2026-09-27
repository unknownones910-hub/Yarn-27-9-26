<?php
/**
 * Inline SVG icons.
 *
 * Inline rather than a sprite sheet or an icon font: no extra request, no
 * flash of missing glyphs, and each icon inherits currentColor so it themes
 * itself. Stroke icons are drawn on a 24x24 grid at 1.6 weight to match the
 * soft, rounded feel of the rest of the design system.
 *
 * Usage:  <?= icon('cart', 22) ?>
 */
declare(strict_types=1);

function icon(string $name, int $size = 24, string $class = ''): string
{
    $paths = [
        // --- navigation & chrome -------------------------------------
        'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'cart'     => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/>',
        'heart'    => '<path d="M20.4 5.6a5.2 5.2 0 0 0-7.4 0L12 6.6l-1-1a5.2 5.2 0 1 0-7.4 7.4l1 1 7.4 7.4 7.4-7.4 1-1a5.2 5.2 0 0 0 0-7.4z"/>',
        'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'grid'     => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
        'chevron-right' => '<path d="M9 5l7 7-7 7"/>',
        'chevron-down'  => '<path d="M5 9l7 7 7-7"/>',
        'arrow-right'   => '<path d="M4 12h16M14 6l6 6-6 6"/>',
        'arrow-left'    => '<path d="M20 12H4M10 6l-6 6 6 6"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',

        // --- trust & commerce ----------------------------------------
        'shield'   => '<path d="M12 3l7.5 3v5.5c0 4.6-3.1 8.6-7.5 9.9-4.4-1.3-7.5-5.3-7.5-9.9V6z"/><path d="M9.2 12.2l2 2 3.6-3.9"/>',
        'truck'    => '<path d="M3 6.5h10.5v9H3z"/><path d="M13.5 10h4l3 3v2.5h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/>',
        'refresh'  => '<path d="M20 11a8 8 0 1 0-.7 4.4"/><path d="M20 5v6h-6"/>',
        'chat'     => '<path d="M20.5 12a7.5 7.5 0 0 1-11 6.7L4 20.5l1.8-5.5A7.5 7.5 0 1 1 20.5 12z"/>',
        'tag'      => '<path d="M3 12.5V4.5a1.5 1.5 0 0 1 1.5-1.5h8l8.5 8.5-9.5 9.5z"/><circle cx="7.8" cy="7.8" r="1.3"/>',
        'gift'     => '<rect x="3" y="9" width="18" height="12" rx="1.6"/><path d="M3 13h18M12 9v12"/><path d="M12 9S9.5 3 7 4.5 9 9 12 9s5-3 2.5-4.5S12 9 12 9z"/>',
        'package'  => '<path d="M21 8.5 12 3.5 3 8.5v7L12 20.5l9-5z"/><path d="M3 8.5 12 13.5l9-5M12 13.5V20.5"/>',
        'star'     => '<path d="M12 3.5l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.9l6-.9z"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.2 12.3l2.6 2.6 5-5.4"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'alert'    => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
        'lock'     => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5 12 13l8.5-6.5"/>',
        'phone'    => '<path d="M6.5 3.5h3l1.5 4-2 1.4a12 12 0 0 0 5.6 5.6l1.4-2 4 1.5v3a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 5 5.1 1.5 1.5 0 0 1 6.5 3.5z"/>',
        'pin'      => '<path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/>',
        'trash'    => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
        'edit'     => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/>',
        'download' => '<path d="M12 3.5v11M7.5 10.5l4.5 4.5 4.5-4.5"/><path d="M4 19.5h16"/>',
        'filter'   => '<path d="M3 5.5h18M6.5 12h11M10 18.5h4"/>',
        'sliders'  => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'sort'     => '<path d="M7 4v16M7 20l-3-3M7 4l3 3M17 20V4M17 4l3 3M17 20l-3-3"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'logout'   => '<path d="M9.5 20H5.5a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 5.5 4h4"/><path d="M15 16l4-4-4-4M19 12H9"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5v.2a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1h.2a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',

        // --- craft ----------------------------------------------------
        'yarn'     => '<circle cx="12" cy="12" r="8.5"/><path d="M6.2 6.9c2.4 1.6 4.4 4.3 5.3 7.4M9.2 4.2c2.7 2 4.8 5.2 5.7 8.9M14.6 4.6c1.9 1.9 3.3 4.4 3.9 7.1M4.1 10.8c1.6 1.3 3 3.1 3.8 5.2"/>',
        'hook'     => '<path d="M8 20V9.5a4 4 0 0 1 8 0V11"/><path d="M16 11a2.2 2.2 0 1 1-3.2 2"/>',
        'needles'  => '<path d="M5 19L16 8M8 4l11 11"/><circle cx="4" cy="20" r="1.4"/><circle cx="20" cy="16" r="1.4"/>',
        'sparkle'  => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M18.5 15.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/>',
        'leaf'     => '<path d="M4 20c0-8 5-14 16-14 0 9-5.5 14-12 14H4z"/><path d="M4 20c3-5 7-8 11-9.5"/>',

        // --- social ---------------------------------------------------
        'instagram'=> '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor" stroke="none"/>',
        'facebook' => '<path d="M14.5 8.5H17V5h-2.5a4 4 0 0 0-4 4v2H8v3.5h2.5V21H14v-6.5h2.5L17 11h-3V9.3c0-.5.2-.8.5-.8z"/>',
        'youtube'  => '<rect x="2.5" y="6" width="19" height="12" rx="3.5"/><path d="M10.5 9.5l5 2.5-5 2.5z"/>',

        // --- theme toggle ---------------------------------------------
        'moon' => '<path d="M20.5 14.3A8.6 8.6 0 0 1 9.7 3.5a8.6 8.6 0 1 0 10.8 10.8z"/>',
        'sun'  => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 12H2M22 12h-2.2"/>'
                  . '<path d="M5.6 5.6 7.2 7.2M16.8 16.8l1.6 1.6M18.4 5.6 16.8 7.2M7.2 16.8l-1.6 1.6"/>',

        // --- textile care symbols (ISO 3758 / GINETEX) ----------------
        // The actual shapes printed on a ball band, not decorative stand-ins:
        // washtub, triangle, square and iron, with a cross over the ones that
        // mean "do not". The whole point of the guide section is to teach the
        // symbol, so it has to BE the symbol.
        //
        // Dots are the temperature scale — one dot 30 degrees, two 40, three 60.
        // They need an explicit fill because the wrapper sets fill="none".
        'care-wash' =>
            '<path d="M2.6 7.2h18.8l-1.5 10.9a2.4 2.4 0 0 1-2.4 2.1H6.5a2.4 2.4 0 0 1-2.4-2.1z"/>'
            . '<path d="M2.6 7.2c1.6-1.9 3.1-1.9 4.7 0s3.1 1.9 4.7 0 3.1-1.9 4.7 0 3.1 1.9 4.7 0"/>'
            . '<circle cx="12" cy="14.4" r="1.05" fill="currentColor" stroke="none"/>',

        'care-handwash' =>
            '<path d="M2.6 7.2h18.8l-1.5 10.9a2.4 2.4 0 0 1-2.4 2.1H6.5a2.4 2.4 0 0 1-2.4-2.1z"/>'
            . '<path d="M2.6 7.2c1.6-1.9 3.1-1.9 4.7 0s3.1 1.9 4.7 0 3.1-1.9 4.7 0 3.1 1.9 4.7 0"/>'
            // a hand dipped into the tub
            . '<path d="M8.7 18.2v-3.6c0-.6.5-1 1-1s1 .4 1 1v-1.5c0-.6.5-1 1-1s1 .4 1 1v.6c0-.6.5-1 1-1s1 .4 1 1v3.1"/>',

        'care-no-bleach' =>
            '<path d="M12 3.6 21.4 20H2.6z"/>'
            . '<path d="M6.4 18.2 17.6 6.2M17.6 18.2 6.4 6.2"/>',

        'care-dry-flat' =>
            '<rect x="2.8" y="4.4" width="18.4" height="15.2" rx="1.6"/>'
            . '<path d="M7.4 12h9.2"/>',

        'care-no-tumble' =>
            '<rect x="2.8" y="4.4" width="18.4" height="15.2" rx="1.6"/>'
            . '<circle cx="12" cy="12" r="4.6"/>'
            . '<path d="M4.6 19.4 19.4 4.6"/>',

        'care-iron' =>
            // flat sole plate, sloped back, pointed toe — an iron from the side
            '<path d="M2.4 17.6h19.2v-1.1c0-3.6-2.9-6.5-6.5-6.5H7.7c-1 0-1.9.6-2.2 1.6z"/>'
            . '<circle cx="10.4" cy="14.2" r=".95" fill="currentColor" stroke="none"/>'
            . '<circle cx="13.9" cy="14.2" r=".95" fill="currentColor" stroke="none"/>',
        'pinterest'=> '<circle cx="12" cy="12" r="9"/><path d="M9.5 20c-.4-1.6 0-3.6.4-5 .3-1.3 1-4 1-4a2.6 2.6 0 0 1-.2-1.2c0-1.2.7-2 1.5-2 .7 0 1 .5 1 1.2 0 .8-.5 1.9-.7 3-.2.9.4 1.6 1.3 1.6 1.6 0 2.7-2 2.7-4.4 0-1.8-1.2-3.2-3.4-3.2a4 4 0 0 0-4.1 4c0 .8.3 1.4.7 1.8.2.2.2.3.1.5l-.2.8c0 .3-.2.4-.5.2-1.2-.5-1.8-1.9-1.8-3.5C7.3 7 9.2 4.5 13 4.5c3 0 5 2.2 5 4.5 0 3.1-1.7 5.4-4.3 5.4-.9 0-1.7-.5-2-1L11 16c-.2.8-.7 1.9-1.1 2.6z"/>',
        'whatsapp' => '<path d="M3.5 20.5l1.3-4.3a8 8 0 1 1 3.2 3.1z"/><path d="M9 9.2c.2-.4.4-.4.6-.4h.5c.2 0 .4 0 .6.5l.7 1.6c0 .2 0 .4-.1.5l-.4.5c-.1.2-.3.3-.1.6a7 7 0 0 0 3 2.6c.3.1.5.1.6 0l.7-.8c.2-.2.3-.2.6-.1l1.6.8c.2.1.4.2.4.4v.6c-.1.4-.7 1-1.2 1.1-.4.1-1 .2-3-.6a10.6 10.6 0 0 1-4.4-3.9c-.3-.5-.8-1.4-.8-2.3 0-.9.5-1.4.7-1.6z"/>',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';

    return sprintf(
        '<svg%s width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
        $cls,
        $size,
        $size,
        $paths[$name]
    );
}

/**
 * Star rating block.
 * Renders filled/empty stars plus an accessible text label.
 */
function stars_html(float $rating, int $count = 0, bool $showCount = true): string
{
    $full = (int) round($rating);
    $out  = '<span class="rating"><span class="stars" role="img" aria-label="'
          . e(number_format($rating, 1)) . ' out of 5 stars">';

    for ($i = 1; $i <= 5; $i++) {
        $filled = $i <= $full;
        // NOT class="empty" — that is the site-wide empty-STATE panel utility,
        // which carries `padding: clamp(32px, 8vw, 96px) 16px` plus a glass
        // fill and border. Every unfilled star inherited all of it and rendered
        // as a tall bordered bar that shoved the rating count sideways.
        $out .= '<svg viewBox="0 0 24 24" width="13" height="13" class="' . ($filled ? '' : 'star-off') . '" '
              . 'fill="' . ($filled ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.5" '
              . 'stroke-linejoin="round" aria-hidden="true">'
              . '<path d="M12 3.5l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.9l6-.9z"/></svg>';
    }

    $out .= '</span>';
    // Gated here rather than at each call site: there are five of them, and a
    // setting that only holds in four places is worse than no setting.
    // Admin → Settings → Numbers shown to customers.
    if (!setting_bool('feat_review_counts', true)) {
        $showCount = false;
    }
    if ($showCount && $count > 0) {
        $out .= '<span>' . number_format($rating, 1) . ' (' . number_format($count) . ')</span>';
    }
    $out .= '</span>';

    return $out;
}
