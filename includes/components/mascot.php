<?php
/**
 * Nesty — the shop mascot.
 *
 * A yarn ball with a face, drawn as inline SVG rather than shipped as an image
 * for three reasons: it stays crisp at every size the hero uses, it costs no
 * extra request on the most important page of the site, and its colours are
 * CSS custom properties, so it re-tints with the theme instead of needing a
 * second file for dark mode.
 *
 * Gradient stops carry CLASSES, not fill attributes. A presentation attribute
 * cannot resolve var(), so `stop-color="var(--x)"` silently renders black —
 * the stop colours live in style.css under `.m-*` instead.
 *
 * Geometry notes, because the pieces have to agree with each other:
 *   · head occupies y 40-270, the ball is centred (200,360) r138 so its top
 *     edge lands at y=222. Every facial feature must finish above that line or
 *     the ball is drawn over the face — the smile is the part that collides
 *     first, which is why it sits at y=186-210 and not lower.
 *   · the paws are placed where the ball's surface actually is at their x,
 *     not at a guessed y: at x=122 the ball edge is y≈245, so cy=262 rests
 *     them ~17px onto it.
 */
declare(strict_types=1);

/**
 * The mascot artwork. Decorative by default — the button around it is named.
 *
 * $idPrefix namespaces the gradient/clip/filter ids. The home page renders the
 * mascot twice (hero and the craft picker) and ids must be unique in a
 * document: duplicates are invalid HTML, and a `url(#{$p}Ball)` in the second copy
 * silently resolves to the first one's gradient, so removing or restyling
 * either instance breaks the other.
 */
function mascot_svg(string $class = 'mascot__art', string $idPrefix = 'm'): string
{
    $p = preg_replace('~[^A-Za-z0-9_-]~', '', $idPrefix) ?: 'm';
    // Strands: rotated ellipses clipped to the ball, each reading as one pass
    // of yarn around the sphere.
    //
    // Density and restraint are what make this look like yarn rather than a
    // wireframe globe. Sixteen high-contrast strokes read as wire; forty thin
    // low-opacity ones blur into fibre. They are also softened by a small
    // gaussian at render — real yarn has no crisp edge.
    //
    // They are grouped into PASSES rather than given individual angles. Giving
    // every strand its own rotation makes each one cross every other, and the
    // result is a wireframe globe — which is exactly what the first attempt
    // looked like. Real winding lays down near-parallel loops at one tilt,
    // then rotates and lays down another pass.
    //
    // Ellipses sharing a rotation and differing only in ry are nested, so
    // within a pass they never cross; they converge at the two ends of the
    // major axis the way real yarn converges at the winding poles. rx is
    // nudged per strand so that convergence is a soft bundle, not a point.
    // Small ry values are thin lens shapes passing straight through the
    // centre, and a handful of those knot into a visible mesh in the middle
    // of the ball. Real winding shows mostly near-tangential passes, so the
    // radii stay in the outer half.
    $passes  = [6, 29, 51, 74, 96, 119, 141, 163];
    $radii   = [72, 90, 105, 118, 128, 134];
    $strandSvg = '';
    $i = 0;
    // NOT $p as the loop key — $p is the id prefix, and a foreach key clobbers
    // it for everything after the loop. Fourth time this bug class has bitten
    // in this codebase; see the CLAUDE.md notes on $rawBody and $items.
    foreach ($passes as $deg) {
        foreach ($radii as $r => $ry) {
            $rx   = 130 + (($i * 3) % 6);
            $tone = ($i % 3 === 1) ? 'b' : (($i % 3 === 2) ? 'c' : 'a');
            $strandSvg .= '<ellipse class="m-strand m-strand--' . $tone . '" cx="200" cy="360"'
                        . ' rx="' . $rx . '" ry="' . $ry . '"'
                        . ' transform="rotate(' . ($deg + ($r % 2 ? 3 : -3)) . ' 200 360)"/>';
            $i++;
        }
    }

    return <<<SVG
<svg class="{$class}" viewBox="0 0 400 540" xmlns="http://www.w3.org/2000/svg"
     aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="{$p}Head" x1=".18" y1="0" x2=".72" y2="1">
      <stop class="m-head-a" offset="0"/>
      <stop class="m-head-b" offset=".58"/>
      <stop class="m-head-c" offset="1"/>
    </linearGradient>
    <radialGradient id="{$p}Ball" cx=".34" cy=".26" r=".92">
      <stop class="m-ball-a" offset="0"/>
      <stop class="m-ball-b" offset=".5"/>
      <stop class="m-ball-c" offset="1"/>
    </radialGradient>
    <radialGradient id="{$p}Gloss" cx=".5" cy=".5" r=".5">
      <stop offset="0" stop-color="#FFFFFF" stop-opacity=".40"/>
      <stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="{$p}Deep" cx=".5" cy=".5" r=".5">
      <stop class="m-deep" offset="0"/>
      <stop class="m-deep-out" offset="1"/>
    </radialGradient>
    <radialGradient id="{$p}Shadow" cx=".5" cy=".5" r=".5">
      <stop class="m-shadow" offset="0"/>
      <stop class="m-shadow-out" offset="1"/>
    </radialGradient>
    <clipPath id="{$p}BallClip"><circle cx="200" cy="360" r="138"/></clipPath>
    <!-- Yarn has no crisp edge. A sub-pixel blur on the strands is the
         difference between fibre and wireframe. -->
    <filter id="{$p}Fuzz" x="-6%" y="-6%" width="112%" height="112%">
      <feGaussianBlur stdDeviation=".9"/>
    </filter>
  </defs>

  <ellipse cx="200" cy="505" rx="132" ry="24" fill="url(#{$p}Shadow)"/>

  <!-- Head sits BEHIND the ball; its lower third is meant to disappear.
       Broad and slightly squared off rather than an egg — the control points
       are pulled out horizontally near the top to flatten the crown. -->
  <path d="M200 34 C300 34 336 78 336 146 C336 218 290 272 200 272
           C110 272 64 218 64 146 C64 78 100 34 200 34 Z" fill="url(#{$p}Head)"/>

  <!-- Loose end, drawn before the ball so it appears to come from under it. -->
  <path class="m-tail" d="M322 400 C352 398 372 412 362 428 C354 440 336 436 334 424"/>

  <circle cx="200" cy="360" r="138" fill="url(#{$p}Ball)"/>
  <g clip-path="url(#{$p}BallClip)">
    <g filter="url(#{$p}Fuzz)">{$strandSvg}</g>
    <ellipse cx="150" cy="300" rx="72" ry="50" transform="rotate(-25 150 300)" fill="url(#{$p}Gloss)"/>
    <ellipse cx="286" cy="436" rx="92" ry="76" fill="url(#{$p}Deep)"/>
  </g>

  <!-- Contact shadow under each paw, so they sit ON the ball rather than float. -->
  <ellipse class="m-paw-sh" cx="122" cy="288" rx="34" ry="14"/>
  <ellipse class="m-paw-sh" cx="278" cy="288" rx="34" ry="14"/>
  <ellipse cx="118" cy="276" rx="35" ry="23" transform="rotate(-18 118 276)" fill="url(#{$p}Head)"/>
  <ellipse cx="282" cy="276" rx="35" ry="23" transform="rotate(18 282 276)" fill="url(#{$p}Head)"/>

  <!-- Face. Every feature finishes above y=222, the ball's top edge. -->
  <g class="m-face">
    <ellipse class="m-blush" cx="122" cy="196" rx="20" ry="12"/>
    <ellipse class="m-blush" cx="278" cy="196" rx="20" ry="12"/>
    <g class="m-eyes">
      <ellipse class="m-eye" cx="158" cy="155" rx="15" ry="19"/>
      <ellipse class="m-eye" cx="242" cy="155" rx="15" ry="19"/>
      <ellipse class="m-spark" cx="152.5" cy="147" rx="5" ry="6"/>
      <ellipse class="m-spark" cx="236.5" cy="147" rx="5" ry="6"/>
    </g>
    <path class="m-smile" d="M170 188 Q200 214 230 188"/>
  </g>
</svg>
SVG;
}

/**
 * What Nesty says. First line is the greeting shown on load; the rest are
 * cycled when the visitor pokes him.
 *
 * Kept server-side so the greeting can use the signed-in name and the real
 * free-shipping threshold rather than a hardcoded number that drifts.
 *
 * $context picks the set. The opening greeting is shared — being recognised by
 * name should not depend on which page you landed on — and only the lines
 * after it change, so each page's mascot has something relevant to say
 * instead of repeating the shop pitch.
 */
function mascot_greetings(?array $customer = null, string $context = 'home'): array
{
    $name = '';
    if ($customer && !empty($customer['full_name'])) {
        // First name only — "Welcome back, Priya" reads warmer than the full
        // name, and a long one would wrap the bubble to three lines.
        $name = trim((string) strtok((string) $customer['full_name'], ' '));
    }

    $hello = $name !== ''
        ? 'Welcome back, ' . $name . '!'
        : 'Hi there — welcome in!';

    $free = money(setting_money('ship_free_threshold', 2499));

    $sets = [
        'home' => [
            'Every shade here was photographed in real daylight.',
            'Free delivery over ' . $free . ', across India.',
            'Not sure how much you need? Try the yarn calculator.',
            'Squish-tested by hand before it ships. Truly.',
        ],
        'about' => [
            'I am Nesty. I mostly supervise.',
            'Every ball here got squished by a real person first.',
            'We started because we could not find honest fibre labels.',
            'Colours may appear slightly different depending on lighting, screen settings and photography.',
            'There is a human on the other end of the phone. Usually mid-project.',
        ],
    ];

    return array_merge([$hello], $sets[$context] ?? $sets['home']);
}
