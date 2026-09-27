<?php
/**
 * Migration 006 — opening set of Journal posts.
 *
 * A PHP migration for the same reason as 005: this is several thousand words of
 * prose full of apostrophes and markup, and escaping it into SQL literals is how
 * you get silent truncation.
 *
 * These are written to be genuinely useful rather than to pad the page. Each one
 * answers a question this shop actually gets asked, and several of them talk a
 * customer OUT of a purchase — which is the point. A journal that only ever says
 * "buy more yarn" is read as advertising and does nothing for trust.
 *
 * Idempotent: matched on slug, so re-running install.php will not duplicate.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$posts = [];

// ---------------------------------------------------------------------
$posts[] = [
    'slug'  => 'which-yarn-should-a-beginner-buy',
    'title' => 'Which yarn should a beginner actually buy?',
    'tags'  => 'Beginners,Choosing yarn',
    'excerpt' => 'Skip the pretty stuff. For your first few projects there is one '
        . 'boring, correct answer, and it costs about a third of what you were about to spend.',
    'meta_description' => 'The best yarn for a beginner in India: light-coloured '
        . 'worsted or DK acrylic or cotton, in a smooth twist. Why chenille, mohair '
        . 'and dark shades make learning harder.',
    'body' => <<<'HTML'
<p>Almost everyone buys the wrong yarn first. Not because they were careless —
because the appealing yarns and the easy yarns are two different sets, and
nothing on the shelf tells you which is which.</p>

<p>Here is the short answer. For your first three or four projects, buy a
<strong>smooth, light-coloured, medium-weight yarn</strong>: worsted or DK, in
acrylic or cotton. That is it. Around &#8377;150&#8211;250 a ball. Save the
interesting yarn for when your hands know what they are doing.</p>

<h2>Why light colours</h2>
<p>You learn to crochet and knit by <em>seeing your stitches</em> — where one
ends, where the next begins, which loop the hook goes under. On a black or
navy yarn you cannot see any of that. You will be working by feel before you
have developed any feel, and every mistake stays invisible until it is twenty
rows back.</p>

<p>Cream, ecru, dusty pink, soft grey, sage. Anything you can photograph
indoors and still make out the stitch definition.</p>

<h2>Why smooth</h2>
<p>Three yarns look wonderful and will make you want to quit:</p>

<ul>
  <li><strong>Chenille and velvet.</strong> Beautiful, and it worms — the pile
      shifts and stitches slide apart. It also hides your stitch structure
      completely, so you cannot count.</li>
  <li><strong>Mohair and any brushed halo yarn.</strong> Nearly impossible to
      undo. Frogging mohair is not a matter of patience; the fibres lock and
      you often lose the yarn.</li>
  <li><strong>Boucl&eacute; and thick-thin novelty yarns.</strong> Uneven
      thickness means uneven tension, and you will blame yourself for a problem
      the yarn created.</li>
</ul>

<p>A plain plied yarn — several strands twisted together, visibly — is the one
that teaches you. You can see it, count it, and pull it out fifty times.</p>

<h2>Cotton or acrylic?</h2>
<p>Both are correct. They fail differently.</p>

<p><strong>Acrylic</strong> has a little give, which forgives tight tension —
and beginners crochet tight. It is cheap enough that ruining a ball costs
nothing, and it takes being pulled out repeatedly without looking tired. Its
downside is that it can feel squeaky against a metal hook, and it will not
block into shape.</p>

<p><strong>Cotton</strong> shows stitches more crisply than anything else,
which makes it genuinely easier to see what you are doing. It has almost no
stretch, so it is less forgiving of a death grip, and it is harder on the
hands over a long session. For amigurumi it is the right answer from day one,
because the stitches need to hold their shape and not let stuffing show
through.</p>

<p>If you are making a toy, buy cotton. If you are making a scarf or a square,
buy either. If your hands ache after ten minutes, that is tension, not the
yarn — loosen your grip and go up a hook size.</p>

<h2>How much to buy</h2>
<p>Two balls. Not ten.</p>

<p>The most common first purchase we see is a full project's worth of a yarn
the person has never touched. Buy two balls, make something small, and find
out whether you like working with it. If you do, come back — and this is the
important part — <strong>order the rest in one go</strong> so the whole project
comes from the same dye lot. Two balls of the same shade bought three weeks
apart can be visibly different.</p>

<h2>What to make first</h2>
<p>Not a scarf. A scarf is two hundred identical rows and most people abandon
one before it becomes a scarf.</p>

<p>Make a granny square, or a dishcloth, or a small basket. Something that is
finished within an evening or two, so you get the whole loop — cast on, work,
finish, hold it — before you get bored. Then make the scarf, if you still want
to.</p>

<p>Our <a href="/yarn-guide.php">yarn guide</a> explains what the numbers on a
ball band mean, and the <a href="/yarn-calculator.php">yarn calculator</a> will
tell you how many balls a project needs once you know what you are making.</p>
HTML,
];

// ---------------------------------------------------------------------
$posts[] = [
    'slug'  => 'dye-lots-explained',
    'title' => 'Dye lots: why two balls of the same shade do not match',
    'tags'  => 'Choosing yarn,Buying',
    'excerpt' => 'The most expensive mistake in this craft is buying half the '
        . 'yarn now and half later. Here is what actually happens in the dye vat.',
    'meta_description' => 'What a dye lot is, why the same yarn shade varies '
        . 'between batches, and how to buy so a project never runs out mid-way.',
    'body' => <<<'HTML'
<p>You buy six balls of Terracotta Rose. Four go into the blanket. You run out,
order two more of the identical shade, and the new ones are — not wrong,
exactly. Just <em>slightly</em> not the same. In daylight, across a large
piece, you can see exactly where you ran out.</p>

<p>This is a dye lot, and it is not a fault.</p>

<h2>What a dye lot is</h2>
<p>Yarn is dyed in batches. A quantity of undyed yarn goes into a vat with a
measured quantity of dye, at a set temperature, for a set time. Every ball from
that vat is a dye lot, and it carries a lot number on the band.</p>

<p>The next batch uses the same recipe. But the base yarn came from a different
bale of fibre with slightly different absorbency, the water was a degree off,
the dye powder came from a different production run. The result is the same
colour to a reasonable eye and a measurably different one to a camera or to a
large flat surface of knitted fabric.</p>

<p>Hand-dyed yarn varies more. Deep saturated shades vary more than pale ones,
because there is more pigment for the variation to show up in. Reds and teals
are the worst offenders.</p>

<h2>Where it shows and where it does not</h2>
<p>It shows badly on anything large and flat worked in one piece: a blanket, a
shawl, the back of a jumper. Your eye reads the whole surface at once and
picks up the boundary immediately.</p>

<p>It barely shows on granny squares, amigurumi, striped work, or anything
where the colour is already broken up. A toy made from two lots of the same
cream is genuinely fine.</p>

<h2>How to buy so it never happens</h2>
<ol>
  <li><strong>Work out the quantity before you order.</strong> The
      <a href="/yarn-calculator.php">yarn calculator</a> will estimate it from
      your project and the ball band figures.</li>
  <li><strong>Add one ball.</strong> Always. Tension varies, patterns lie, and
      a spare ball costs far less than a visible seam.</li>
  <li><strong>Buy it all in one order.</strong> We pick from one lot wherever
      we can, and a single order is what makes that possible.</li>
  <li><strong>Keep a ball band.</strong> The lot number is on it. If you do
      need more, tell us the number and we will check what we have.</li>
</ol>

<h2>If you are already halfway through</h2>
<p>You have three options, in order of how well they work:</p>

<p><strong>Alternate rows.</strong> Work two rows from the old lot, two from
the new, for ten or so rows. The transition becomes a gradient instead of a
line, and the eye stops finding it.</p>

<p><strong>Move the boundary somewhere structural.</strong> Start the new lot
at a seam, a border, a sleeve — anywhere the eye already expects a change.</p>

<p><strong>Use it for the edging.</strong> If the difference is small, running
the new lot as a deliberate border reads as a design decision.</p>

<h2>What we do about it</h2>
<p>Every product page states the current lot. We photograph each shade in
natural daylight rather than under a lamp, because warm indoor light flatters
and misleads. If you need to match something exactly, order the
<a href="/shade-card.php">physical shade card</a> first — a screen cannot tell
you what a colour is, and no two screens agree anyway.</p>

<p>We do not accept returns on wound yarn, and dye lot variation on its own is
not a defect — that is in our
<a href="/returns-and-refunds">returns policy</a> in plain words. But if what
arrived is not what the page showed, that is a different matter and we will
sort it out.</p>
HTML,
];

// ---------------------------------------------------------------------
$posts[] = [
    'slug'  => 'cotton-or-acrylic-indian-climate',
    'title' => 'Cotton or acrylic, for the weather you actually live in',
    'tags'  => 'Choosing yarn,Fibres',
    'excerpt' => 'Most yarn advice online is written for people whose problem is '
        . 'staying warm. For most of India the problem is the opposite.',
    'meta_description' => 'Choosing between cotton, acrylic, wool and blends for '
        . 'Indian weather — humidity, monsoon drying times, and what actually gets worn.',
    'body' => <<<'HTML'
<p>Nearly every yarn guide you will read was written somewhere cold. The
assumptions underneath it — that wool is the default, that warmth is the goal,
that things dry indoors in a day — do not survive a Chennai August or a Mumbai
monsoon.</p>

<p>So here is the version for the weather most of us actually have.</p>

<h2>Cotton</h2>
<p><strong>Best for:</strong> almost everything worn or used in a hot, humid
climate. Tops, bags, dishcloths, table linen, baby items, amigurumi.</p>

<p>Cotton breathes, takes a hot wash, and does not hold body heat. It shows
stitch definition better than any other common fibre, which is why amigurumi
is almost always worked in cotton — the stitches stay crisp and the stuffing
does not show through.</p>

<p>Two things to know. Cotton has no memory: a cotton garment stretches under
its own weight and does not spring back, so a heavy cotton cardigan will grow.
And it is heavy — a cotton blanket is a genuinely large object to hold in your
lap while you make it.</p>

<p>Mercerised cotton is treated to be smoother, stronger and shinier. It shows
stitches even more sharply and takes dye more deeply. It is the right choice
for anything you want to look finished rather than rustic.</p>

<h2>Acrylic</h2>
<p><strong>Best for:</strong> blankets, toys, learning, anything that will be
washed constantly, anything for a child.</p>

<p>Acrylic is unfairly maligned. It is machine washable, it does not felt, it
does not shrink, moths ignore it, and it costs a fraction of wool. For a baby
blanket that will be washed twice a week for two years, it is not a compromise
— it is the correct fibre.</p>

<p>Its real weakness is that it does not breathe. An acrylic jumper in Indian
weather is unpleasant in a specific, sweaty way. Keep it for things that sit
<em>on</em> you rather than against you.</p>

<p>Anti-pilling acrylic is worth the small premium for anything that gets
handled a lot. Ordinary acrylic develops little bobbles at friction points
within a few months.</p>

<h2>Wool</h2>
<p><strong>Best for:</strong> Himachal, Kashmir, Uttarakhand, the North-East,
Delhi and Punjab in December&#8211;January, and hill stations. Genuinely not
much else.</p>

<p>Wool is a wonderful fibre and most of India has nowhere to wear it. If you
are knitting for someone in a cold place, or for winter travel, it is
unmatched — it insulates while damp, it blocks into shape and stays there, and
it has the elasticity that makes ribbing work.</p>

<p>Superwash wool has been treated so it can be machine washed without
felting. For socks and for anything going to a household that will not
hand-wash, insist on it.</p>

<h2>Blends</h2>
<p>Cotton-acrylic blends are the quiet workhorse for this climate: most of
cotton's breathability, some of acrylic's lightness and washability, and less
of cotton's tendency to sag. If you cannot decide, a blend is rarely the wrong
answer.</p>

<p>Bamboo and modal blends drape beautifully and feel cool against skin, which
makes them excellent for summer tops and shawls. They are slippery to work
with and show every tension inconsistency, so they are not a beginner's
fibre.</p>

<h2>The monsoon problem nobody mentions</h2>
<p>In a humid monsoon, a thick cotton or wool item can take three or four days
to dry, and in that time it can develop a smell that does not entirely wash
out. This is a real reason to prefer acrylic and blends for large items made
between June and September, and a real reason to make bulky wool projects in
the dry months.</p>

<p>If you are blocking something in monsoon weather, block it under a fan. Not
in the sun — direct sun fades dyed yarn faster than most people expect,
particularly reds and purples.</p>

<h2>The short version</h2>
<ul>
  <li>Worn against the skin in heat &rarr; <strong>cotton</strong> or a cotton
      blend</li>
  <li>Washed constantly, or made for a child &rarr; <strong>acrylic</strong></li>
  <li>Amigurumi &rarr; <strong>cotton</strong>, every time</li>
  <li>Genuinely cold weather &rarr; <strong>wool</strong>, superwash if it will
      be machine washed</li>
  <li>Summer drape &rarr; <strong>bamboo or modal blend</strong>, once you have
      some experience</li>
  <li>Large project in monsoon &rarr; something that <strong>dries fast</strong></li>
</ul>

<p>The <a href="/yarn-guide.php">yarn guide</a> has the weights, ply names and
care symbols, and you can filter the <a href="/shop.php">shop</a> by fibre if
you already know what you are after.</p>
HTML,
];

// ---------------------------------------------------------------------
$posts[] = [
    'slug'  => 'how-to-read-a-ball-band',
    'title' => 'How to read a ball band',
    'tags'  => 'Beginners,Reference',
    'excerpt' => 'That little paper sleeve has everything you need to substitute '
        . 'one yarn for another, and most people throw it away.',
    'meta_description' => 'A plain-English guide to every number and symbol on a '
        . 'yarn ball band: weight, length, gauge, hook and needle size, and care symbols.',
    'body' => <<<'HTML'
<p>The band around a ball of yarn is a specification sheet. Once you can read
it you can substitute yarns confidently, work out how much you need, and stop
guessing at hook sizes. Here is every part of it.</p>

<h2>Weight in grams</h2>
<p>How much the ball weighs — 25 g, 50 g and 100 g are the usual sizes. This
tells you nothing about thickness. A 100 g ball of lace yarn and a 100 g ball
of chunky are the same weight and wildly different lengths.</p>

<h2>Length in metres</h2>
<p><strong>This is the number that matters</strong>, and the one people ignore.
Patterns should be written in metres, not balls, because "6 balls" means
nothing without knowing whose balls.</p>

<p>Metres per gram is the real measure of thickness. 250 m per 100 g is a DK
weight. 100 m per 100 g is chunky. If you are comparing two yarns, compare
this ratio and you will not go far wrong.</p>

<h2>Weight category</h2>
<p>The confusing one, because "weight" here means thickness, not grams. You
will see a mix of naming systems on the same shelf:</p>

<ul>
  <li><strong>Lace / 2 ply</strong> — shawls, very fine work</li>
  <li><strong>Fingering / 4 ply / sock</strong> — socks, fine garments</li>
  <li><strong>Sport / 5 ply</strong> — baby items, light garments</li>
  <li><strong>DK / 8 ply / light worsted</strong> — the most useful all-rounder</li>
  <li><strong>Worsted / 10 ply / aran</strong> — jumpers, blankets, bags</li>
  <li><strong>Chunky / 12 ply / bulky</strong> — fast blankets, winter accessories</li>
  <li><strong>Super chunky</strong> — arm knitting, very fast projects</li>
</ul>

<p>"Ply" originally meant the number of strands twisted together and now mostly
does not — a modern 8 ply may be four strands. Treat ply names as size labels,
not as counts.</p>

<h2>The little square with numbers</h2>
<p>A gauge or tension swatch: usually something like <strong>22 sts &times; 30
rows = 10 &times; 10 cm</strong>. It means that if you work this yarn at the
recommended hook or needle size, you should get 22 stitches across ten
centimetres.</p>

<p>Use it to compare yarns. If a pattern calls for a yarn with 22 stitches to
10 cm, and yours says 22, they will behave similarly. If yours says 18, your
finished item will come out larger.</p>

<p>It is a starting point, not a promise. Your own tension is yours. If size
matters — a garment, anything that must fit — work a swatch and measure it
before you commit.</p>

<h2>Hook and needle symbols</h2>
<p>A little hook icon and a little needle icon with a millimetre size beside
each. That is the manufacturer's recommendation for a standard fabric.</p>

<p>Go up a size for a softer, drapier fabric — shawls, garments. Go down a size
for a dense fabric that will not let stuffing through, which is what amigurumi
needs. Amigurumi is almost always worked a size or two tighter than the band
suggests, and that is deliberate.</p>

<h2>The dye lot number</h2>
<p>Usually printed or stamped separately from the shade name, often as a bare
number. Balls with the same lot number came from the same dye batch and will
match exactly. See <a href="/blog/dye-lots-explained">why this matters</a> —
briefly, it is the difference between a blanket and a blanket with a visible
line across it.</p>

<h2>Care symbols</h2>
<p>The same symbols as on clothing labels.</p>

<ul>
  <li><strong>Tub</strong> — machine washable; the number inside is the maximum
      temperature in &deg;C</li>
  <li><strong>Tub with a hand</strong> — hand wash only</li>
  <li><strong>Crossed-out tub</strong> — do not wash; dry clean</li>
  <li><strong>Triangle</strong> — bleach allowed; crossed out means no bleach</li>
  <li><strong>Square with a circle</strong> — tumble dry; dots are heat, crossed
      out means do not</li>
  <li><strong>Square with a horizontal line</strong> — dry flat. Important for
      anything heavy and wet, which will stretch on a hanger</li>
  <li><strong>Iron</strong> — dots are temperature. Most acrylic melts; take
      this seriously</li>
</ul>

<h2>Keep one band per project</h2>
<p>Tuck one band into your project bag and keep it. When somebody asks how to
wash the thing you made them, it is the only honest answer you have — and if
you need another ball, the shade name and lot number are right there.</p>

<p>The <a href="/yarn-guide.php">yarn guide</a> has a full conversion table for
weights, ply names and hook sizes if you want it in one place.</p>
HTML,
];

// ---------------------------------------------------------------------

// install.php does NOT load bootstrap, so there is no db_all()/db_insert() here
// — only the raw $conn it hands us. Prepared statements all the same.
$inserted = 0;
$now      = date('Y-m-d H:i:s');
$author   = 'The Yarn Nest';

// Backdate them a few days apart so the Journal does not look like it was
// written in one sitting the day the shop opened.
$offsets = [21, 14, 7, 2];

$find = $conn->prepare('SELECT id FROM blog_posts WHERE slug = ?');
$ins  = $conn->prepare(
    'INSERT INTO blog_posts
        (slug, title, excerpt, body, author, tags, meta_title, meta_description,
         status, published_at, created_at)
     VALUES (?,?,?,?,?,?,?,?, "published", ?, ?)'
);

foreach ($posts as $i => $p) {
    $find->bind_param('s', $p['slug']);
    $find->execute();
    if ($find->get_result()->fetch_row()) {
        continue;                       // already seeded — idempotent
    }

    $body      = trim($p['body']);
    $published = date('Y-m-d H:i:s', strtotime('-' . ($offsets[$i] ?? 1) . ' days'));

    $ins->bind_param(
        'ssssssssss',
        $p['slug'], $p['title'], $p['excerpt'], $body, $author, $p['tags'],
        $p['title'], $p['meta_description'], $published, $now
    );
    $ins->execute();
    $inserted++;
}

return $inserted;
