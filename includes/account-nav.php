<?php
/**
 * Account section navigation.
 *
 * One list, two shapes: a sticky sidebar from 900px, a horizontal scroll-snap
 * tab strip below it. No hamburger inside a hamburger.
 */
declare(strict_types=1);

function account_sections(): array
{
    return [
        ''              => ['Overview',      'grid',     'account/'],
        'orders'        => ['My orders',     'package',  'account/orders.php'],
        'addresses'     => ['Addresses',     'pin',      'account/addresses.php'],
        'wishlist'      => ['Wishlist',      'heart',    'account/wishlist.php'],
        'reviews'       => ['My reviews',    'star',     'account/reviews.php'],
        'coupons'       => ['Coupons',       'tag',      'account/coupons.php'],
        'support'       => ['Support',       'chat',     'account/support.php'],
        'profile'       => ['Profile',       'user',     'account/profile.php'],
        'password'      => ['Password',      'lock',     'account/password.php'],
        'notifications' => ['Notifications', 'mail',     'account/notifications.php'],
    ];
}

function account_nav(string $active = ''): void
{
    $pending = account_pending_counts();
    ?>
    <nav class="account-nav" aria-label="Account sections">
      <ul>
        <?php foreach (account_sections() as $key => [$label, $ico, $href]): ?>
          <li>
            <a href="<?= e(url($href)) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>>
              <?= icon($ico, 18) ?>
              <span class="min0 truncate"><?= e($label) ?></span>
              <?php if (!empty($pending[$key])): ?>
                <span class="account-nav__badge"><?= (int) $pending[$key] ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <form method="post" action="<?= e(url('logout.php')) ?>" class="account-nav__out">
        <?= csrf_field() ?>
        <button class="btn-link" type="submit"><?= icon('logout', 16) ?> Sign out</button>
      </form>
    </nav>
    <?php
}

/** Small counts shown as badges — only things that want the customer's attention. */
function account_pending_counts(): array
{
    static $counts = null;
    if ($counts !== null) {
        return $counts;
    }

    $cid = customer_id();
    if ($cid <= 0) {
        return $counts = [];
    }

    return $counts = [
        'orders'  => (int) db_val(
            'SELECT COUNT(*) FROM orders
             WHERE customer_id = ?
               AND status IN ("paid","processing","packed","shipped","out_for_delivery")',
            [$cid]
        ),
        'support' => (int) db_val(
            'SELECT COUNT(*) FROM support_tickets
             WHERE customer_id = ? AND status IN ("open","awaiting_customer")',
            [$cid]
        ),
    ];
}

/** Delivered items the customer has not reviewed yet. */
function account_reviewable_items(int $customerId, int $limit = 10): array
{
    return db_all(
        'SELECT oi.id AS item_id, oi.order_id, oi.product_id, oi.variant_id,
                oi.product_name, oi.product_slug, oi.shade_name, oi.color_hex, oi.image,
                o.order_no, o.delivered_at
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.customer_id = ?
           AND o.status = "delivered"
           AND oi.product_id IS NOT NULL
           AND NOT EXISTS (
                 SELECT 1 FROM reviews r
                 WHERE r.customer_id = o.customer_id
                   AND r.product_id = oi.product_id
                   AND r.order_id = o.id
               )
         GROUP BY oi.product_id, o.id
         ORDER BY o.delivered_at DESC
         LIMIT ' . (int) $limit,
        [$customerId]
    );
}
