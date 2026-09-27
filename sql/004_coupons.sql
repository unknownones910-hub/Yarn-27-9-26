-- =====================================================================
--  Migration 004 — starter discount codes
--
--  Real codes, safe defaults. Edit or deactivate them in the admin panel;
--  they are seeded once and never overwritten on re-run.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO coupons
  (code, description, type, value, min_order, max_discount, usage_limit,
   per_customer_limit, first_order_only, starts_at, ends_at, is_active)
VALUES
  ('WELCOME10', '10% off your first order', 'percent', 10.00, 499.00, 300.00,
   NULL, 1, 1, NULL, NULL, 1),

  ('FREESHIP', 'Free delivery, no minimum', 'free_shipping', 0.00, 0.00, NULL,
   500, 2, 0, NULL, NULL, 1),

  ('YARN100', 'Flat 100 off orders above 1499', 'fixed', 100.00, 1499.00, NULL,
   NULL, 3, 0, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE description = description;

INSERT INTO applied_migrations (filename) VALUES ('004_coupons.sql')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
