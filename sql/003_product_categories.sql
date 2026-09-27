-- =====================================================================
--  Migration 003 — many-to-many product ↔ category
--
--  Why: a yarn belongs to several taxonomies at once. "Milk Cotton DK" is
--  a Crochet yarn AND an Amigurumi yarn AND a Cotton yarn AND a DK-weight
--  yarn. `products.category_id` can only hold one of those, so it stays as
--  the PRIMARY category (used for breadcrumbs and the canonical URL) while
--  this table drives every category page and filter.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_categories (
  product_id  INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, category_id),
  KEY idx_cat (category_id),
  CONSTRAINT fk_pc_product  FOREIGN KEY (product_id)  REFERENCES products(id)   ON DELETE CASCADE,
  CONSTRAINT fk_pc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every product's primary category also becomes a membership row,
-- so nothing disappears from its category page after this migration.
INSERT IGNORE INTO product_categories (product_id, category_id)
SELECT id, category_id FROM products WHERE category_id IS NOT NULL;

INSERT INTO applied_migrations (filename) VALUES ('003_product_categories.sql')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
