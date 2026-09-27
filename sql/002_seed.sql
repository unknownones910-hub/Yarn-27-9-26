-- =====================================================================
--  The Yarn Nest — baseline seed (settings, categories, admin, pincodes)
--  Migration 002. Safe to re-run: every insert is idempotent.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Settings.  Replace every [bracketed] value before go-live.
-- ---------------------------------------------------------------------
INSERT INTO settings (k, v) VALUES
  ('store_name',          'The Yarn Nest'),
  ('store_legal_name',    'The Yarn Nest'),
  ('store_address',       '[Street address], [City], [State] [PIN], India'),
  ('store_phone',         '+91 00000 00000'),
  ('store_whatsapp',      '910000000000'),
  ('store_email',         'hello@example.com'),
  ('store_gstin',         ''),
  ('grievance_name',      '[Grievance Officer Name]'),
  ('grievance_email',     'grievance@example.com'),
  ('jurisdiction_city',   '[City]'),
  ('jurisdiction_state',  '[State]'),

  ('ship_flat_rate',      '69'),
  ('ship_free_threshold', '999'),
  ('gift_wrap_fee',       '49'),
  ('cod_enabled',         '0'),
  ('gst_enabled',         '0'),
  ('return_window_days',  '7'),
  ('dispatch_days',       '1-2'),
  ('order_prefix',        'YN'),

  ('feat_reviews',        '1'),
  ('feat_blog',           '1'),
  ('feat_patterns',       '1'),
  ('feat_wholesale',      '1'),
  ('feat_gift_wrap',      '1'),
  ('feat_newsletter',     '1'),

  ('announcement_text',   'Free shipping on orders above ₹999 · Ships across India'),
  ('announcement_on',     '1'),

  ('social_instagram',    ''),
  ('social_facebook',     ''),
  ('social_youtube',      ''),
  ('social_pinterest',    ''),

  ('seo_title',           'The Yarn Nest — Cotton, Acrylic & Wool Yarn Online in India'),
  ('seo_description',     'Soft, honest yarn for crochet and knitting. Accurate shade photos, clear fibre labels, fast delivery across India.')
ON DUPLICATE KEY UPDATE v = v;   -- never overwrite a value the owner has edited

-- ---------------------------------------------------------------------
--  Categories
-- ---------------------------------------------------------------------
INSERT INTO categories (name, slug, kind, icon, sort_order, description) VALUES
  ('Crochet',      'crochet',      'craft', 'hook',    10, 'Smooth, well-plied yarns that show stitch definition beautifully — ideal for crochet.'),
  ('Knitting',     'knitting',     'craft', 'needles', 20, 'Even, elastic yarns that glide on the needles and block into a soft, drapey fabric.'),
  ('Amigurumi',    'amigurumi',    'craft', 'toy',     30, 'Tight-twist cotton yarns that hold their shape and hide the stuffing — made for amigurumi.'),
  ('Macramé',      'macrame',      'craft', 'knot',    40, 'Strong cotton cords and ropes with a soft fringe, for wall hangings and plant hangers.'),
  ('Weaving',      'weaving',      'craft', 'loom',    50, 'Textured and chunky yarns that build a tactile woven surface fast.'),

  ('Cotton',       'cotton',       'fiber', NULL,      10, 'Breathable, durable and machine washable. The everyday workhorse for Indian weather.'),
  ('Acrylic',      'acrylic',      'fiber', NULL,      20, 'Soft, affordable and colourfast. Great for blankets, toys and beginner projects.'),
  ('Wool',         'wool',         'fiber', NULL,      30, 'Warm and springy, with excellent stitch memory. For winter wear and shawls.'),
  ('Bamboo',       'bamboo',       'fiber', NULL,      40, 'Silky sheen and beautiful drape, with a cool hand-feel for summer garments.'),
  ('Chenille',     'chenille',     'fiber', NULL,      50, 'Velvety and plush. Instantly cosy blankets, cushions and soft toys.'),
  ('Jute & Cord',  'jute-cord',    'fiber', NULL,      60, 'Rustic natural fibres and cotton cords for bags, baskets and macramé.'),
  ('Silk Blend',   'silk-blend',   'fiber', NULL,      70, 'A touch of luxury — soft lustre and gorgeous drape for special projects.'),
  ('Recycled',     'recycled',     'fiber', NULL,      80, 'Made from reclaimed fibre. Kind on the planet, easy on the price.'),

  ('Lace',         'lace-weight',        'weight', NULL, 10, 'CYC 0 · 1–2 ply. Featherweight yarn for shawls and delicate lacework.'),
  ('Super Fine',   'super-fine-weight',  'weight', NULL, 20, 'CYC 1 · 3 ply / sock. Fine and hardwearing, perfect for socks and baby wear.'),
  ('Fine',         'fine-weight',        'weight', NULL, 30, 'CYC 2 · 4 ply / sport. Light garments with crisp stitch detail.'),
  ('Light / DK',   'dk-weight',          'weight', NULL, 40, 'CYC 3 · 8 ply. The most versatile weight — sweaters, toys, accessories.'),
  ('Medium',       'medium-weight',      'weight', NULL, 50, 'CYC 4 · 10 ply / worsted. Fast, sturdy fabric for everyday makes.'),
  ('Bulky',        'bulky-weight',       'weight', NULL, 60, 'CYC 5 · 12 ply / chunky. Quick projects with big, cosy stitches.'),
  ('Super Bulky',  'super-bulky-weight', 'weight', NULL, 70, 'CYC 6 · 14 ply. Blankets and scarves finished in an evening.'),
  ('Jumbo',        'jumbo-weight',       'weight', NULL, 80, 'CYC 7 · 16+ ply. Arm knitting and statement home décor.'),

  ('Kits & Bundles',      'kits-and-bundles',      'other', NULL, 10, 'Everything a project needs in one box — yarn, hook and pattern.'),
  ('Tools & Accessories', 'tools-and-accessories', 'other', NULL, 20, 'Hooks, needles, stitch markers, scissors and project bags.'),
  ('Sale',                'sale',                  'other', NULL, 30, 'Reduced shades and end-of-lot yarn, while stock lasts.')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
--  Admin accounts are deliberately NOT seeded here.
--
--  Hardcoding an address in a file that ships means every copy of this
--  codebase grants the same person access, and it is the kind of thing that
--  quietly survives a handover. The first admin is created instead by
--  install.php, which asks for the address and a password, or on a machine
--  with shell access by tools/set-admin-password.php.
--
--  Whichever route, the normalized Gmail must ALSO appear in
--  config/admin-config.php -> allowlist. Both are required.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Starter pincodes for the delivery estimator.
--  Import a full India dataset later; unknown pincodes fall back to the
--  default ETA rather than blocking checkout.
-- ---------------------------------------------------------------------
INSERT INTO pincodes (pincode, city, state, is_serviceable, eta_days_min, eta_days_max) VALUES
  ('400001', 'Mumbai',     'Maharashtra',   1, 2, 4),
  ('411001', 'Pune',       'Maharashtra',   1, 2, 4),
  ('440001', 'Nagpur',     'Maharashtra',   1, 3, 5),
  ('110001', 'New Delhi',  'Delhi',         1, 2, 4),
  ('560001', 'Bengaluru',  'Karnataka',     1, 3, 5),
  ('600001', 'Chennai',    'Tamil Nadu',    1, 3, 5),
  ('500001', 'Hyderabad',  'Telangana',     1, 3, 5),
  ('700001', 'Kolkata',    'West Bengal',   1, 3, 6),
  ('380001', 'Ahmedabad',  'Gujarat',       1, 3, 5),
  ('302001', 'Jaipur',     'Rajasthan',     1, 3, 6),
  ('226001', 'Lucknow',    'Uttar Pradesh', 1, 4, 6),
  ('682001', 'Kochi',      'Kerala',        1, 4, 7),
  ('751001', 'Bhubaneswar','Odisha',        1, 4, 7),
  ('781001', 'Guwahati',   'Assam',         1, 6, 10),
  ('190001', 'Srinagar',   'Jammu and Kashmir', 1, 7, 12)
ON DUPLICATE KEY UPDATE city = VALUES(city);

INSERT INTO applied_migrations (filename) VALUES ('002_seed.sql')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
