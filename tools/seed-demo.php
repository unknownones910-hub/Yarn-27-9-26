<?php
/**
 * Demo catalog seeder — DEVELOPMENT ONLY.
 *
 * Deliberately NOT a sql/ migration: install.php applies those automatically,
 * and demo products must never land on a production database by accident.
 *
 * Run:
 *     C:\xampp\php\php.exe tools/seed-demo.php
 *     C:\xampp\php\php.exe tools/seed-demo.php --force    (wipe & reseed)
 *
 * Creates products, shades, category links and a handful of real review rows
 * so the ratings shown on cards are backed by actual data rather than invented
 * numbers.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

define('YN_NO_ERROR_PAGE', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);

$existing = (int) db_val('SELECT COUNT(*) FROM products');
if ($existing > 0 && !$force) {
    exit("There are already {$existing} products. Re-run with --force to wipe and reseed.\n");
}

if ($force && $existing > 0) {
    echo "Wiping existing catalog…\n";
    db_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['reviews', 'product_categories', 'product_images', 'product_variants', 'products'] as $t) {
        db_exec("TRUNCATE TABLE `{$t}`");
    }
    db_exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ---------------------------------------------------------------------
//  Shade pool. Real yarn shade names with plausible hexes.
// ---------------------------------------------------------------------
$SHADES = [
    ['Snow White',       '#F7F5F0', 'white'],
    ['Ivory Cream',      '#F0E6D2', 'cream'],
    ['Oatmeal',          '#DFD3BE', 'cream'],
    ['Sand Dune',        '#D2BE9C', 'cream'],
    ['Butter Yellow',    '#F2D06B', 'yellow'],
    ['Mustard Field',    '#C79320', 'yellow'],
    ['Golden Hour',      '#E0A73A', 'yellow'],
    ['Apricot',          '#E8A277', 'orange'],
    ['Burnt Orange',     '#C86A32', 'orange'],
    ['Terracotta Rose',  '#B4552F', 'orange'],
    ['Clay Pot',         '#A9542F', 'brown'],
    ['Rust',             '#9A4526', 'orange'],
    ['Blush Pink',       '#EBBFC2', 'pink'],
    ['Rose Quartz',      '#DE9AA6', 'pink'],
    ['Fuchsia',          '#B63F73', 'pink'],
    ['Cherry Red',       '#B62B2B', 'red'],
    ['Deep Maroon',      '#772430', 'red'],
    ['Rosewood',         '#8A4A4A', 'red'],
    ['Lavender Mist',    '#C3B4D8', 'purple'],
    ['Plum',             '#6E4478', 'purple'],
    ['Aubergine',        '#4B2E4F', 'purple'],
    ['Sky Blue',         '#A9CBE0', 'blue'],
    ['Denim',            '#4A6C8C', 'blue'],
    ['Indigo Dusk',      '#39507A', 'blue'],
    ['Teal Deep',        '#2C6B6B', 'blue'],
    ['Mint Sprig',       '#B4D8C0', 'green'],
    ['Sage Morning',     '#8FA383', 'green'],
    ['Olive Grove',      '#6B7440', 'green'],
    ['Forest',           '#33543C', 'green'],
    ['Fern',             '#5C8A4E', 'green'],
    ['Latte',            '#B99872', 'brown'],
    ['Chestnut',         '#7C5334', 'brown'],
    ['Chocolate',        '#4E3527', 'brown'],
    ['Pebble Grey',      '#B9B4AC', 'grey'],
    ['Storm Grey',       '#7C7A76', 'grey'],
    ['Charcoal',         '#3F3B37', 'grey'],
    ['Jet Black',        '#232020', 'black'],
    ['Coral Reef',       '#E37A63', 'orange'],
    ['Peacock',          '#1F6E82', 'blue'],
    ['Wine',             '#652439', 'red'],
    ['Camel',            '#C39A6B', 'brown'],
    ['Duck Egg',         '#BCD6D2', 'blue'],
    ['Raspberry',        '#A32E52', 'pink'],
    ['Confetti Multi',   '#C97BA0', 'multi'],
];

// ---------------------------------------------------------------------
//  Products
// ---------------------------------------------------------------------
$WEIGHT_CAT = [
    'lace' => 'lace-weight', 'super_fine' => 'super-fine-weight', 'fine' => 'fine-weight',
    'light' => 'dk-weight', 'medium' => 'medium-weight', 'bulky' => 'bulky-weight',
    'super_bulky' => 'super-bulky-weight', 'jumbo' => 'jumbo-weight',
];

/**
 * shade_from / shade_count pick a slice of $SHADES, so every product has a
 * distinct-looking palette without hand-writing 200 variant rows.
 */
$PRODUCTS = [
    ['YN-MCD', 'Milk Cotton DK', 'Soft, round and squishy — the everyday favourite', 'Nest Studio',
     '100% Milk Cotton', 'light', '8-ply', 50, 125, 4.0, 4.0, '22 sts x 28 rows / 10 cm',
     260, 210, ['crochet','amigurumi','knitting'], 'cotton', 0, 10, 1, 1,
     'A smooth, lightly mercerised milk cotton that shows stitch definition beautifully and softens further with every wash.',
     "Our best-selling base, and the one we recommend to anyone starting out. The ply is tight enough that your hook never splits it, but soft enough that a finished blanket still drapes.\n\nWorks for amigurumi, garments, market bags and baby blankets. Colourfast and machine washable at 30°C."],

    ['YN-MC4', 'Mercerised Cotton 4-ply', 'Crisp stitch definition with a subtle sheen', 'Nest Studio',
     '100% Mercerised Cotton', 'fine', '4-ply', 50, 170, 3.0, 3.25, '26 sts x 34 rows / 10 cm',
     210, 185, ['crochet','knitting'], 'cotton', 4, 9, 0, 1,
     'Mercerised for strength and a soft lustre. Ideal for doilies, summer tops and detailed colourwork.',
     "The mercerising process gives this cotton its quiet shine and makes it noticeably stronger than untreated cotton. Stitches stay sharp, which is exactly what you want for lacework and fine colourwork."],

    ['YN-SAW', 'Soft Acrylic Worsted', 'Great value, endlessly washable', 'Craftline',
     '100% Premium Acrylic', 'medium', '10-ply', 100, 200, 5.0, 5.0, '18 sts x 24 rows / 10 cm',
     190, 150, ['crochet','knitting'], 'acrylic', 8, 12, 0, 0,
     'A dependable worsted acrylic that stays soft after washing and does not pill quickly.',
     "Acrylic gets an unfair reputation, usually from cheap yarn. This one is spun to a higher standard: soft on the skin, colourfast, and it survives a washing machine and a toddler."],

    ['YN-VCH', 'Velvet Chenille', 'Plush, weighty and irresistible', 'Nest Studio',
     '100% Polyester Chenille', 'bulky', '12-ply', 100, 90, 6.5, 7.0, '12 sts x 16 rows / 10 cm',
     360, 320, ['crochet','knitting','amigurumi'], 'chenille', 12, 10, 1, 1,
     'Velvety chenille that works up fast into blankets, cushions and cuddly toys.',
     "Chenille needs a slightly looser tension than you think — go up a hook size if your stitches feel tight. Worth it: nothing else gives that velvet surface.\n\nTip: use stitch markers generously, chenille hides its stitches well."],

    ['YN-MER', 'Merino Wool Fingering', 'Fine superwash merino with real spring', 'Loom & Co',
     '80% Superwash Merino, 20% Nylon', 'super_fine', '3-ply', 50, 200, 2.75, 3.0, '30 sts x 40 rows / 10 cm',
     599, 480, ['knitting','crochet'], 'wool', 16, 8, 0, 1,
     'Superwash merino with a nylon core for durability — the classic sock and shawl yarn.',
     "The nylon content is what lets a hand-knitted sock survive real wear. Springy, warm without bulk, and it takes dye beautifully."],

    ['YN-BAM', 'Bamboo Breeze Sport', 'Cool, silky and wonderfully drapey', 'Nest Studio',
     '70% Bamboo, 30% Cotton', 'fine', '5-ply', 50, 155, 3.5, 3.75, '24 sts x 30 rows / 10 cm',
     340, 290, ['crochet','knitting'], 'bamboo', 20, 8, 0, 1,
     'Bamboo blended with cotton for a cool hand-feel and a beautiful liquid drape.',
     "Made for Indian summers. Bamboo wicks moisture and feels cool against the skin, and the cotton keeps it from being too slippery to work with."],

    ['YN-CHB', 'Chunky Blanket Yarn', 'A whole blanket in a weekend', 'Craftline',
     '100% Acrylic', 'super_bulky', '14-ply', 200, 80, 9.0, 10.0, '9 sts x 12 rows / 10 cm',
     499, 450, ['crochet','knitting','weaving'], 'acrylic', 24, 8, 0, 0,
     'Super bulky acrylic that turns into a finished throw faster than anything else we stock.',
     "One of those yarns that makes you feel like a fast crocheter. A lap blanket takes about six balls and an evening or two."],

    ['YN-JUT', 'Jute Craft Cord 3mm', 'Rustic natural fibre with real structure', 'Craftline',
     '100% Natural Jute', 'jumbo', '—', 200, 45, 10.0, null, '—',
     199, 199, ['macrame','crochet','weaving'], 'jute-cord', 28, 5, 0, 0,
     'Sturdy natural jute cord for baskets, plant hangers and rustic bags.',
     "Holds its shape without stiffening agents, so baskets stand up on their own. Natural fibre, so expect slight colour variation between batches — that is the character, not a fault."],

    ['YN-MAC', 'Macramé Cotton Cord 4mm', 'Soft single-twist cord that fringes beautifully', 'Nest Studio',
     '100% Recycled Cotton', 'jumbo', '—', 250, 60, null, null, '—',
     399, 349, ['macrame','weaving'], 'jute-cord', 33, 6, 0, 1,
     'Single-twist recycled cotton cord — combs out into a soft, full fringe.',
     "The single twist is what makes the fringe: run a pet brush through the ends and it blooms. Strong enough for wall hangings and plant hangers that actually hold a pot."],

    ['YN-BSA', 'Baby Soft Acrylic DK', 'Gentle enough for newborn skin', 'Craftline',
     '100% Anti-Pilling Acrylic', 'light', '8-ply', 100, 280, 4.0, 4.0, '22 sts x 28 rows / 10 cm',
     185, 165, ['crochet','knitting','amigurumi'], 'acrylic', 0, 12, 0, 0,
     'Anti-pilling acrylic in soft shades, made for baby blankets and layettes.',
     "Hypoallergenic and machine washable at 40°C, which matters more than anything else for baby items. The anti-pilling finish means it still looks good after twenty washes."],

    ['YN-SIL', 'Silk Blend Lace', 'A little luxury for a special project', 'Loom & Co',
     '55% Silk, 45% Merino', 'lace', '2-ply', 50, 400, 2.5, 2.75, '32 sts x 42 rows / 10 cm',
     790, 690, ['knitting','crochet'], 'silk-blend', 16, 7, 0, 0,
     'Silk and merino spun fine, with a soft halo and gorgeous drape.',
     "400 metres in a 50g ball, so one ball goes a very long way — most lace shawls need two. Blocks out into something that looks far more expensive than it was."],

    ['YN-REC', 'Recycled Cotton Aran', 'Kind on the planet, easy on the price', 'Nest Studio',
     '80% Recycled Cotton, 20% Polyester', 'medium', '10-ply', 100, 170, 5.0, 5.0, '17 sts x 23 rows / 10 cm',
     260, 230, ['crochet','knitting','weaving'], 'recycled', 30, 8, 0, 1,
     'Spun from reclaimed cotton offcuts — slightly heathered, pleasingly imperfect.',
     "Because it is made from reclaimed fibre, you will see tiny flecks of other colours through it. We think that is the best thing about it."],

    ['YN-SOC', 'Sock Yarn Superwash', 'Hardwearing, self-striping ready', 'Loom & Co',
     '75% Superwash Wool, 25% Nylon', 'super_fine', '4-ply', 100, 400, 2.5, 2.5, '30 sts x 42 rows / 10 cm',
     720, 650, ['knitting'], 'wool', 20, 8, 0, 0,
     'A proper sock yarn — reinforced, superwash and built to be walked on.',
     "100g makes a pair of adult socks with yarn left over. Superwash means the finished socks go in the machine, which is the only way socks actually get worn."],

    ['YN-PIM', 'Pima Cotton DK', 'Long-staple cotton, exceptionally smooth', 'Loom & Co',
     '100% Pima Cotton', 'light', '8-ply', 50, 115, 4.0, 4.0, '21 sts x 28 rows / 10 cm',
     300, 260, ['crochet','knitting'], 'cotton', 5, 9, 0, 1,
     'Long-staple Pima cotton — smoother and stronger than standard cotton, with almost no fuzz.',
     "The longer fibre length is what you feel: it is noticeably smoother in the hand and produces a cleaner fabric with much less pilling over time."],

    ['YN-ALP', 'Alpaca Blend Chunky', 'Feather-light warmth', 'Loom & Co',
     '50% Alpaca, 50% Wool', 'bulky', '12-ply', 100, 110, 6.0, 6.5, '14 sts x 18 rows / 10 cm',
     790, 720, ['knitting','crochet'], 'wool', 28, 7, 0, 0,
     'Alpaca blended with wool — warmer than its weight suggests, with a soft halo.',
     "Alpaca has no lanolin, so it suits people who find wool itchy. It also has very little memory, which is why we blend it with wool for structure."],

    ['YN-GLI', 'Glitter Acrylic DK', 'A quiet sparkle running through', 'Craftline',
     '96% Acrylic, 4% Metallic Polyester', 'light', '8-ply', 100, 250, 4.0, 4.0, '22 sts x 28 rows / 10 cm',
     240, 210, ['crochet','knitting','amigurumi'], 'acrylic', 12, 8, 1, 0,
     'A fine metallic thread plied through soft acrylic — festive without being scratchy.',
     "The metallic strand is fine enough that it never feels rough. Good for festive amigurumi, party decorations and anything for a child who wants sparkle."],

    ['YN-TWD', 'Tweed Wool Aran', 'Flecked, woolly and traditional', 'Loom & Co',
     '90% Wool, 10% Viscose Tweed', 'medium', '10-ply', 100, 160, 5.0, 5.0, '18 sts x 24 rows / 10 cm',
     590, 540, ['knitting','crochet'], 'wool', 30, 7, 0, 0,
     'A classic tweed with coloured neps running through a woolly base.',
     "Tweed hides uneven tension better than any other yarn, which makes it unexpectedly forgiving for a first sweater."],

    ['YN-RIB', 'Ribbon Yarn', 'Flat tape that works up with real texture', 'Nest Studio',
     '100% Polyester Ribbon', 'bulky', '—', 100, 70, 7.0, 8.0, '11 sts x 14 rows / 10 cm',
     320, 280, ['crochet','weaving','macrame'], 'recycled', 36, 6, 0, 0,
     'A flat ribbon tape that creates a rippled, woven-looking fabric.',
     "Keep your tension even and let the ribbon lie flat rather than twisting — the texture comes from the tape itself, not the stitch."],

    ['YN-AMI', 'Amigurumi Cotton 8-ply', 'Tight twist, no stuffing show-through', 'Nest Studio',
     '100% Combed Cotton', 'light', '8-ply', 50, 130, 3.0, 3.5, '24 sts x 30 rows / 10 cm',
     215, 195, ['amigurumi','crochet'], 'cotton', 2, 12, 0, 1,
     'Spun with a tighter twist specifically so stuffing never peeks through your stitches.',
     "Amigurumi is worked at a much tighter gauge than the ball band suggests — usually two hook sizes down. This yarn is built to take that without fighting you."],

    ['YN-MOH', 'Mohair Silk Lace', 'A whisper of a yarn with a soft halo', 'Loom & Co',
     '72% Kid Mohair, 28% Silk', 'lace', '1-ply', 25, 210, 3.5, 4.0, '24 sts x 30 rows / 10 cm',
     920, 850, ['knitting'], 'silk-blend', 18, 6, 0, 0,
     'Kid mohair and silk — almost weightless, with a halo that softens every stitch.',
     "Often held together with another yarn to add warmth and halo without weight. Unravelling mohair is genuinely difficult, so count your stitches as you go."],

    ['YN-ORG', 'Organic Cotton Worsted', 'GOTS-certified, undyed options', 'Nest Studio',
     '100% Organic Cotton', 'medium', '10-ply', 100, 180, 5.0, 5.0, '18 sts x 24 rows / 10 cm',
     340, 310, ['crochet','knitting','weaving'], 'cotton', 0, 7, 0, 0,
     'Organically grown cotton, including undyed naturals in their original shade.',
     "The undyed shades are the actual colour of the cotton boll — no bleaching, no dye. They soften considerably after the first wash."],

    ['YN-NYL', 'Nylon Blend Stretch', 'A little give, a lot of recovery', 'Craftline',
     '85% Cotton, 15% Nylon', 'fine', '5-ply', 50, 165, 3.5, 3.5, '25 sts x 32 rows / 10 cm',
     270, 240, ['crochet','knitting'], 'cotton', 22, 7, 0, 0,
     'Cotton with a nylon core for stretch and recovery — cuffs and socks stay put.',
     "The nylon does the work at the edges: ribbing springs back instead of going baggy after an afternoon."],

    ['YN-TSH', 'T-shirt Yarn (Recycled)', 'Made from jersey offcuts', 'Craftline',
     '95% Recycled Cotton Jersey, 5% Elastane', 'jumbo', '—', 400, 100, 10.0, null, '—',
     449, 399, ['crochet','macrame','weaving'], 'recycled', 33, 6, 0, 0,
     'Thick jersey strips wound into a ball — for rugs, baskets and floor cushions.',
     "One ball makes a decent-sized basket. Because it is reclaimed jersey, colours vary between batches, so buy what you need for a project in one go."],

    ['YN-WBC', 'Wool Blend Chunky', 'Warm, affordable and quick', 'Craftline',
     '50% Wool, 50% Acrylic', 'bulky', '12-ply', 100, 120, 6.0, 6.0, '14 sts x 19 rows / 10 cm',
     420, 380, ['knitting','crochet'], 'wool', 26, 8, 0, 0,
     'Half wool for warmth, half acrylic for washability and price.',
     "The blend most people actually want: warm enough for a winter hat, but you can still put it in the machine."],

    // ---- tools & accessories ------------------------------------------
    ['YN-HKS', 'Ergonomic Crochet Hook Set', '9 hooks, 2.0–6.0 mm, soft grip', 'Nest Studio',
     'Aluminium with silicone grip', null, null, null, null, null, null, null,
     1290, 990, ['crochet'], 'tools-and-accessories', 34, 3, 0, 1,
     'Nine aluminium hooks with cushioned grips, in a zip case.',
     "The grip is the point. If you crochet for more than an hour at a stretch, a cushioned handle is the difference between finishing a project and putting it down."],

    ['YN-NDL', 'Bamboo Circular Needle Set', '8 sizes, 80 cm cable', 'Loom & Co',
     'Bamboo with nylon cable', null, null, null, null, null, null, null,
     1690, 1450, ['knitting'], 'tools-and-accessories', 40, 2, 0, 0,
     'Warm, light bamboo circulars with smooth joins that do not snag.',
     "Bamboo grips the yarn slightly, which is exactly what you want with slippery cotton and silk blends."],

    ['YN-MRK', 'Locking Stitch Markers', 'Pack of 40, four colours', 'Craftline',
     'Coloured plastic', null, null, null, null, null, null, null,
     249, 199, ['crochet','knitting','amigurumi'], 'tools-and-accessories', 12, 4, 0, 0,
     'Locking markers that stay put in amigurumi rounds.',
     "Forty is not too many. They migrate into sofas."],

    ['YN-BAG', 'Canvas Project Bag', 'Holds four balls and your hooks', 'Nest Studio',
     'Cotton canvas', null, null, null, null, null, null, null,
     799, 699, ['crochet','knitting'], 'tools-and-accessories', 1, 3, 0, 1,
     'A sturdy canvas bag with an inner pocket and a yarn guide grommet.',
     "The grommet lets you feed yarn out while the ball stays in the bag — no more chasing a ball across the floor."],
];

// ---------------------------------------------------------------------
//  Reviews pool
// ---------------------------------------------------------------------
$REVIEW_POOL = [
    [5, 'Exactly as pictured', 'The shade matched the website perfectly, which honestly surprised me. Soft and easy to work with.'],
    [5, 'My new go-to', 'Third order of this. Consistent quality every time and the dye lot info on the page is genuinely useful.'],
    [4, 'Lovely, slightly thinner than expected', 'Beautiful yarn and lovely colour. Runs a touch finer than the ball band suggests, so swatch first.'],
    [5, 'Fast delivery', 'Ordered on Monday, had it by Thursday in Pune. Well packed, no damage.'],
    [4, 'Good value', 'For the price this is very good. Not the softest yarn I have used but it holds its shape well.'],
    [5, 'Perfect for amigurumi', 'No stuffing showing through at all. Tight twist, does not split when I work into the back loop.'],
    [3, 'Nice but splits a little', 'Colour is gorgeous. It does split if you are not careful with a sharp hook — worth knowing.'],
    [5, 'Blocked out beautifully', 'Made a shawl with two balls. Blocked out to almost double and the drape is stunning.'],
    [4, 'Softer after washing', 'Felt slightly stiff on the ball but softened a lot after the first wash, as described.'],
];
$REVIEWERS = ['Priya S.', 'Aarti M.', 'Meera R.', 'Sneha K.', 'Divya N.', 'Kavita P.', 'Ritu J.', 'Anjali T.', 'Farah Q.', 'Nisha B.'];

// ---------------------------------------------------------------------
//  Seed
// ---------------------------------------------------------------------
$catIds = [];
foreach (db_all('SELECT id, slug FROM categories') as $c) {
    $catIds[$c['slug']] = (int) $c['id'];
}

$nProducts = 0;
$nVariants = 0;
$nReviews  = 0;

mt_srand(20260804);   // stable output between runs

foreach ($PRODUCTS as $i => $p) {
    [$sku, $name, $subtitle, $brand, $fiber, $weight, $ply, $ballG, $lenM,
     $hook, $needle, $gauge, $mrp, $price, $crafts, $fiberSlug,
     $shadeFrom, $shadeCount, $isNew, $isFeatured, $shortDesc, $longDesc] = $p;

    $slug = unique_slug($name, 'products');

    // Only about a third of the catalog carries a discount. A shop where
    // every single card shouts "% off" reads as fake and undercuts the
    // no-dark-patterns rule in PLAN.md §4.
    if ($i % 3 !== 0) {
        $mrp = $price;
    }

    $productId = db_insert(
        'INSERT INTO products
            (category_id, sku_base, slug, name, subtitle, brand, short_desc, long_desc,
             fiber, yarn_weight, ply, ball_weight_g, length_m, hook_mm, needle_mm, gauge,
             care_text, country_of_origin, mrp, price, is_active, is_featured, is_new,
             sold_count, sort_order, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?, DATE_SUB(NOW(), INTERVAL ? DAY))',
        [
            $catIds[$fiberSlug] ?? null, $sku, $slug, $name, $subtitle, $brand,
            $shortDesc, $longDesc, $fiber, $weight, $ply, $ballG, $lenM,
            $hook, $needle, $gauge,
            'Machine wash cold on a gentle cycle. Dry flat in shade. Do not bleach. Warm iron if needed.',
            'India', $mrp, $price, $isFeatured, $isNew,
            mt_rand(0, 240), $i, mt_rand(1, 120),
        ]
    );
    $nProducts++;

    // ---- category links (craft + fibre + weight) ----------------------
    $links = $crafts;
    $links[] = $fiberSlug;
    if ($weight !== null && isset($WEIGHT_CAT[$weight])) {
        $links[] = $WEIGHT_CAT[$weight];
    }
    if ($mrp > $price) {
        $links[] = 'sale';
    }
    foreach (array_unique($links) as $slugName) {
        if (isset($catIds[$slugName])) {
            db_exec(
                'INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)',
                [$productId, $catIds[$slugName]]
            );
        }
    }

    // ---- shades -------------------------------------------------------
    $dyeLot = mt_rand(20, 31) . chr(mt_rand(65, 68));
    for ($k = 0; $k < $shadeCount; $k++) {
        $shade = $SHADES[($shadeFrom + $k) % count($SHADES)];

        // A realistic spread: mostly healthy stock, a few low, one or two out.
        $roll  = mt_rand(1, 100);
        $stock = $roll <= 8 ? 0 : ($roll <= 22 ? mt_rand(1, 4) : mt_rand(8, 60));

        db_insert(
            'INSERT INTO product_variants
                (product_id, sku, shade_name, shade_code, color_hex, color_family,
                 dye_lot, stock, low_stock_at, is_active, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)',
            [
                $productId,
                $sku . '-' . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT),
                $shade[0],
                (string) (101 + $k),
                $shade[1],
                $shade[2],
                $dyeLot,
                $stock,
                5,
                $k,
            ]
        );
        $nVariants++;
    }

    // ---- reviews (about two thirds of products get some) --------------
    if (mt_rand(1, 3) > 1) {
        $howMany = mt_rand(1, 5);
        $picks   = (array) array_rand($REVIEW_POOL, min($howMany, count($REVIEW_POOL)));
        $sum     = 0;
        $count   = 0;

        foreach ($picks as $pi) {
            [$rating, $title, $body] = $REVIEW_POOL[$pi];
            db_insert(
                'INSERT INTO reviews
                    (product_id, customer_id, author_name, rating, title, body,
                     is_verified, status, helpful_count, created_at)
                 VALUES (?, NULL, ?, ?, ?, ?, 1, "approved", ?, DATE_SUB(NOW(), INTERVAL ? DAY))',
                [
                    $productId,
                    $REVIEWERS[array_rand($REVIEWERS)],
                    $rating, $title, $body,
                    mt_rand(0, 14),
                    mt_rand(1, 90),
                ]
            );
            $sum += $rating;
            $count++;
            $nReviews++;
        }

        // Ratings shown on cards are computed from real rows, never invented.
        db_exec(
            'UPDATE products SET rating_avg = ?, rating_count = ? WHERE id = ?',
            [round($sum / $count, 2), $count, $productId]
        );
    }
}

// ---------------------------------------------------------------------
//  A couple of collections
// ---------------------------------------------------------------------
$collections = [
    ['Beginner Starter Picks', 'beginner-starter-picks',
     'Forgiving yarns that do not split, in weights that work up fast enough to keep you going.'],
    ['Monsoon Cotton Edit', 'monsoon-cotton-edit',
     'Breathable cottons and bamboo blends for humid months — quick to dry, cool to wear.'],
];

foreach ($collections as $ci => [$cname, $cslug, $cdesc]) {
    $existingCol = db_val('SELECT id FROM collections WHERE slug = ?', [$cslug]);
    $colId = $existingCol
        ? (int) $existingCol
        : db_insert('INSERT INTO collections (name, slug, description, is_active, sort_order) VALUES (?,?,?,1,?)',
                    [$cname, $cslug, $cdesc, $ci]);

    $picks = $ci === 0
        ? ['YN-MCD', 'YN-SAW', 'YN-BSA', 'YN-CHB', 'YN-AMI', 'YN-HKS']
        : ['YN-MC4', 'YN-BAM', 'YN-PIM', 'YN-ORG', 'YN-NYL', 'YN-REC'];

    foreach ($picks as $n => $skuBase) {
        $pid = db_val('SELECT id FROM products WHERE sku_base = ? LIMIT 1', [$skuBase]);
        if ($pid) {
            db_exec('INSERT IGNORE INTO collection_products (collection_id, product_id, sort_order) VALUES (?,?,?)',
                    [$colId, (int) $pid, $n]);
        }
    }
}

echo "Seeded:\n";
echo "  {$nProducts} products\n";
echo "  {$nVariants} shades\n";
echo "  {$nReviews} reviews\n";
echo "  " . count($collections) . " collections\n";
echo "\nVisit http://localhost/shop.php\n";
