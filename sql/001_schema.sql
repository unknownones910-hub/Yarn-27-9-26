-- =====================================================================
--  The Yarn Nest — core schema
--  Migration 001. Additive only: never edit an applied migration file,
--  add 002_*.sql, 003_*.sql and so on.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  Migration bookkeeping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS applied_migrations (
  filename   VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Settings
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  k          VARCHAR(64) NOT NULL PRIMARY KEY,
  v          TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Rate limiting (OTP, contact form, tracking lookups, reviews)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket       VARCHAR(190) NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bucket (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Customers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email             VARCHAR(190) NOT NULL,          -- exactly as typed
  email_key         VARCHAR(190) NOT NULL,          -- normalized gmail (unique identity)
  full_name         VARCHAR(120) NULL,
  phone             VARCHAR(15)  NULL,
  email_verified_at DATETIME NULL,
  status            ENUM('active','blocked') NOT NULL DEFAULT 'active',
  marketing_opt_in  TINYINT(1) NOT NULL DEFAULT 0,
  craft_prefs       VARCHAR(120) NULL,
  skill_level       ENUM('beginner','intermediate','advanced') NULL,
  birthday          DATE NULL,
  orders_count      INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  last_login_at     DATETIME NULL,
  deleted_at        DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_key (email_key),
  KEY idx_created (created_at),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_addresses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  label       ENUM('home','work','other') NOT NULL DEFAULT 'home',
  full_name   VARCHAR(120) NOT NULL,
  phone       VARCHAR(15)  NOT NULL,
  line1       VARCHAR(180) NOT NULL,
  line2       VARCHAR(180) NULL,
  landmark    VARCHAR(120) NULL,
  city        VARCHAR(80)  NOT NULL,
  state       VARCHAR(60)  NOT NULL,
  pincode     CHAR(6)      NOT NULL,
  country     VARCHAR(60)  NOT NULL DEFAULT 'India',
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id, is_default),
  CONSTRAINT fk_addr_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_key    VARCHAR(190) NOT NULL,
  purpose      ENUM('login','checkout','admin_login','email_change') NOT NULL DEFAULT 'login',
  code_hash    CHAR(64) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  ip           VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lookup (email_key, purpose, consumed_at, expires_at),
  KEY idx_rate (email_key, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_remember_tokens (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT UNSIGNED NOT NULL,
  selector       CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at     DATETIME NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_selector (selector),
  KEY idx_cust (customer_id),
  CONSTRAINT fk_rmb_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Catalog
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id        INT UNSIGNED NULL,
  name             VARCHAR(100) NOT NULL,
  slug             VARCHAR(120) NOT NULL,
  kind             ENUM('craft','fiber','weight','other') NOT NULL DEFAULT 'other',
  description      TEXT NULL,
  image            VARCHAR(255) NULL,
  icon             VARCHAR(40)  NULL,
  meta_title       VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  sort_order       SMALLINT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug),
  KEY idx_parent (parent_id),
  KEY idx_kind (kind, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id      INT UNSIGNED NULL,
  sku_base         VARCHAR(40)  NOT NULL,
  slug             VARCHAR(160) NOT NULL,
  name             VARCHAR(180) NOT NULL,
  subtitle         VARCHAR(180) NULL,
  brand            VARCHAR(80)  NULL,
  short_desc       VARCHAR(400) NULL,
  long_desc        MEDIUMTEXT   NULL,
  -- yarn specification
  fiber            VARCHAR(140) NULL,
  yarn_weight      ENUM('lace','super_fine','fine','light','medium','bulky','super_bulky','jumbo') NULL,
  ply              VARCHAR(20)  NULL,
  ball_weight_g    SMALLINT UNSIGNED NULL,
  length_m         SMALLINT UNSIGNED NULL,
  hook_mm          DECIMAL(3,1) NULL,
  needle_mm        DECIMAL(3,1) NULL,
  gauge            VARCHAR(80)  NULL,
  care_text        VARCHAR(255) NULL,
  country_of_origin VARCHAR(60) NOT NULL DEFAULT 'India',
  -- commerce
  mrp              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  hsn_code         VARCHAR(12)  NULL,
  tax_rate         DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  -- state
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  is_featured      TINYINT(1) NOT NULL DEFAULT 0,
  is_new           TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count     INT UNSIGNED NOT NULL DEFAULT 0,
  sold_count       INT UNSIGNED NOT NULL DEFAULT 0,
  meta_title       VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  sort_order       SMALLINT NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY idx_cat (category_id, is_active),
  KEY idx_active (is_active, created_at),
  KEY idx_weight (yarn_weight),
  KEY idx_price (price),
  KEY idx_featured (is_featured, is_active),
  FULLTEXT KEY ft_search (name, subtitle, short_desc, fiber, brand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  sku            VARCHAR(50)  NOT NULL,
  shade_name     VARCHAR(80)  NOT NULL,
  shade_code     VARCHAR(20)  NULL,
  color_hex      CHAR(7)      NOT NULL DEFAULT '#CCCCCC',
  color_family   ENUM('red','pink','orange','yellow','green','blue','purple','brown','grey','black','white','cream','multi') NOT NULL DEFAULT 'multi',
  dye_lot        VARCHAR(30)  NULL,
  image          VARCHAR(255) NULL,
  price_override DECIMAL(10,2) NULL,
  stock          INT NOT NULL DEFAULT 0,
  low_stock_at   INT NOT NULL DEFAULT 5,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sku (sku),
  KEY idx_prod (product_id, is_active, sort_order),
  KEY idx_family (color_family),
  KEY idx_stock (stock),
  CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NULL,
  path       VARCHAR(255) NOT NULL,
  alt        VARCHAR(200) NULL,
  kind       ENUM('image','video') NOT NULL DEFAULT 'image',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_prod (product_id, sort_order),
  KEY idx_var (variant_id),
  CONSTRAINT fk_img_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collections (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(140) NOT NULL,
  description TEXT NULL,
  banner      VARCHAR(255) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_products (
  collection_id INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, product_id),
  KEY idx_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id INT UNSIGNED NOT NULL,
  delta      INT NOT NULL,
  reason     ENUM('order','cancel','return','manual','import','correction') NOT NULL,
  ref_type   VARCHAR(20) NULL,
  ref_id     INT UNSIGNED NULL,
  note       VARCHAR(200) NULL,
  admin_id   INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_var (variant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Cart, wishlist, coupons
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id      INT UNSIGNED NULL,
  session_token    CHAR(40) NULL,
  status           ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
  reminder_sent_at DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id, status),
  KEY idx_token (session_token),
  KEY idx_abandon (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id         INT UNSIGNED NOT NULL,
  variant_id      INT UNSIGNED NOT NULL,
  qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  saved_for_later TINYINT(1) NOT NULL DEFAULT 0,
  added_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_variant (cart_id, variant_id),
  KEY idx_var (variant_id),
  CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wishlists (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT UNSIGNED NOT NULL,
  variant_id     INT UNSIGNED NOT NULL,
  notify_restock TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_c_v (customer_id, variant_id),
  KEY idx_var (variant_id),
  CONSTRAINT fk_wish_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(40) NOT NULL,
  description        VARCHAR(180) NULL,
  type               ENUM('percent','fixed','free_shipping') NOT NULL,
  value              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  min_order          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_discount       DECIMAL(10,2) NULL,
  usage_limit        INT UNSIGNED NULL,
  per_customer_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  used_count         INT UNSIGNED NOT NULL DEFAULT 0,
  first_order_only   TINYINT(1) NOT NULL DEFAULT 0,
  starts_at          DATETIME NULL,
  ends_at            DATETIME NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code),
  KEY idx_active (is_active, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id   INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  order_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coupon (coupon_id),
  KEY idx_cust (customer_id),
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Orders & payments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no        VARCHAR(20) NOT NULL,
  customer_id     INT UNSIGNED NULL,
  email           VARCHAR(190) NOT NULL,
  phone           VARCHAR(15)  NOT NULL,
  status          ENUM('pending_payment','paid','processing','packed','shipped','out_for_delivery',
                       'delivered','cancelled','refunded','returned','failed') NOT NULL DEFAULT 'pending_payment',
  payment_status  ENUM('unpaid','paid','partially_refunded','refunded','failed') NOT NULL DEFAULT 'unpaid',
  payment_method  ENUM('razorpay','cod') NOT NULL DEFAULT 'razorpay',
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_total  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_total  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gift_wrap_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  grand_total     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency        CHAR(3) NOT NULL DEFAULT 'INR',
  coupon_code     VARCHAR(40) NULL,
  ship_name       VARCHAR(120) NOT NULL,
  ship_phone      VARCHAR(15)  NOT NULL,
  ship_line1      VARCHAR(180) NOT NULL,
  ship_line2      VARCHAR(180) NULL,
  ship_landmark   VARCHAR(120) NULL,
  ship_city       VARCHAR(80)  NOT NULL,
  ship_state      VARCHAR(60)  NOT NULL,
  ship_pincode    CHAR(6)      NOT NULL,
  ship_country    VARCHAR(60)  NOT NULL DEFAULT 'India',
  bill_same       TINYINT(1) NOT NULL DEFAULT 1,
  bill_json       TEXT NULL,
  customer_note   VARCHAR(500) NULL,
  admin_note      VARCHAR(500) NULL,
  courier         VARCHAR(80)  NULL,
  awb             VARCHAR(60)  NULL,
  tracking_url    VARCHAR(255) NULL,
  placed_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         DATETIME NULL,
  shipped_at      DATETIME NULL,
  delivered_at    DATETIME NULL,
  cancelled_at    DATETIME NULL,
  cancel_reason   VARCHAR(255) NULL,
  ip              VARCHAR(45) NULL,
  user_agent      VARCHAR(255) NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_no (order_no),
  KEY idx_cust (customer_id, placed_at),
  KEY idx_status (status, placed_at),
  KEY idx_email (email),
  KEY idx_pending (status, placed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NULL,
  variant_id   INT UNSIGNED NULL,
  -- immutable snapshot: products get renamed, repriced and deleted
  sku          VARCHAR(50)  NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  product_slug VARCHAR(160) NULL,
  shade_name   VARCHAR(80)  NULL,
  color_hex    CHAR(7)      NULL,
  image        VARCHAR(255) NULL,
  unit_price   DECIMAL(10,2) NOT NULL,
  qty          SMALLINT UNSIGNED NOT NULL,
  line_total   DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_id),
  KEY idx_prod (product_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status   VARCHAR(30) NOT NULL,
  note        VARCHAR(255) NULL,
  changed_by  ENUM('system','admin','customer') NOT NULL DEFAULT 'system',
  admin_id    INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id, created_at),
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            INT UNSIGNED NOT NULL,
  provider            VARCHAR(20) NOT NULL DEFAULT 'razorpay',
  provider_order_id   VARCHAR(60) NULL,
  provider_payment_id VARCHAR(60) NULL,
  provider_signature  VARCHAR(255) NULL,
  method              VARCHAR(30) NULL,
  amount              DECIMAL(10,2) NOT NULL,
  status              ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
  error_code          VARCHAR(60) NULL,
  error_desc          VARCHAR(255) NULL,
  raw_response        MEDIUMTEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order (order_id),
  KEY idx_provider_order (provider_order_id),
  UNIQUE KEY uq_pay (provider_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhooks (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id     VARCHAR(80) NOT NULL,
  event_type   VARCHAR(60) NOT NULL,
  payload      MEDIUMTEXT NOT NULL,
  processed_at DATETIME NULL,
  error        VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           INT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(60) NULL,
  amount             DECIMAL(10,2) NOT NULL,
  reason             VARCHAR(255) NULL,
  status             ENUM('initiated','processed','failed') NOT NULL DEFAULT 'initiated',
  admin_id           INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  items_json  TEXT NOT NULL,
  reason      ENUM('damaged','wrong_item','not_as_described','quality','changed_mind','other') NOT NULL,
  comments    VARCHAR(600) NULL,
  photos_json TEXT NULL,
  status      ENUM('requested','approved','rejected','picked','received','refunded') NOT NULL DEFAULT 'requested',
  admin_note  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order (order_id),
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Content & engagement
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  variant_id    INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,
  order_id      INT UNSIGNED NULL,
  author_name   VARCHAR(120) NULL,
  rating        TINYINT UNSIGNED NOT NULL,
  title         VARCHAR(140) NULL,
  body          TEXT NULL,
  photos_json   TEXT NULL,
  is_verified   TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
  admin_reply   VARCHAR(600) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prod (product_id, status, created_at),
  KEY idx_status (status, created_at),
  CONSTRAINT fk_rev_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(80)  NOT NULL,
  title            VARCHAR(180) NOT NULL,
  body             MEDIUMTEXT   NOT NULL,
  meta_title       VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(180) NOT NULL,
  title            VARCHAR(200) NOT NULL,
  excerpt          VARCHAR(400) NULL,
  body             MEDIUMTEXT NOT NULL,
  cover            VARCHAR(255) NULL,
  author           VARCHAR(80) NULL,
  tags             VARCHAR(200) NULL,
  meta_title       VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY idx_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patterns (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug               VARCHAR(160) NOT NULL,
  title              VARCHAR(200) NOT NULL,
  summary            VARCHAR(400) NULL,
  craft              ENUM('crochet','knitting','macrame','other') NOT NULL DEFAULT 'crochet',
  difficulty         ENUM('beginner','easy','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  yarn_weight        VARCHAR(20) NULL,
  cover              VARCHAR(255) NULL,
  pdf_path           VARCHAR(255) NULL,
  suggested_products VARCHAR(120) NULL,
  downloads          INT UNSIGNED NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category   VARCHAR(60)  NOT NULL,
  question   VARCHAR(300) NOT NULL,
  answer     TEXT NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_cat (category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banners (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement     ENUM('hero','strip','collection') NOT NULL DEFAULT 'hero',
  title         VARCHAR(180) NULL,
  subtitle      VARCHAR(300) NULL,
  image_desktop VARCHAR(255) NULL,
  image_mobile  VARCHAR(255) NULL,
  cta_text      VARCHAR(60)  NULL,
  cta_url       VARCHAR(255) NULL,
  starts_at     DATETIME NULL,
  ends_at       DATETIME NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  KEY idx_place (placement, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind        ENUM('contact','wholesale') NOT NULL DEFAULT 'contact',
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  phone       VARCHAR(15) NULL,
  subject     VARCHAR(180) NULL,
  order_no    VARCHAR(20) NULL,
  message     TEXT NOT NULL,
  extra_json  TEXT NULL,
  status      ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
  admin_reply TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status, created_at),
  KEY idx_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  order_id    INT UNSIGNED NULL,
  subject     VARCHAR(180) NOT NULL,
  status      ENUM('open','awaiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id, status),
  KEY idx_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  sender     ENUM('customer','admin') NOT NULL,
  body       TEXT NOT NULL,
  attachment VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id, created_at),
  CONSTRAINT fk_sm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  source        VARCHAR(40) NULL,
  status        ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending',
  confirm_token CHAR(40) NULL,
  confirmed_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email),
  KEY idx_token (confirm_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restock_alerts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id  INT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  notified_at DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_var_email (variant_id, email),
  KEY idx_pending (variant_id, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pincodes (
  pincode        CHAR(6) NOT NULL PRIMARY KEY,
  city           VARCHAR(80) NOT NULL,
  state          VARCHAR(60) NOT NULL,
  is_serviceable TINYINT(1) NOT NULL DEFAULT 1,
  eta_days_min   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  eta_days_max   TINYINT UNSIGNED NOT NULL DEFAULT 7
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Admin & ops
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  email_key     VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  role          ENUM('owner','manager','staff') NOT NULL DEFAULT 'staff',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_key (email_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED NULL,
  action     VARCHAR(80) NOT NULL,
  entity     VARCHAR(40) NULL,
  entity_id  INT UNSIGNED NULL,
  detail     VARCHAR(500) NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_id, created_at),
  KEY idx_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email            VARCHAR(190) NOT NULL,
  subject             VARCHAR(255) NOT NULL,
  template            VARCHAR(60) NULL,
  status              ENUM('sent','failed','skipped') NOT NULL,
  provider_message_id VARCHAR(120) NULL,
  error               VARCHAR(500) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_to (to_email),
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO applied_migrations (filename) VALUES ('001_schema.sql')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
