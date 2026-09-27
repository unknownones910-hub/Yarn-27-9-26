<?php
/**
 * Customer list.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

$q    = get_str('q', 80);
$sort = get_str('sort', 20);

$where  = ['c.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[]  = '(c.email LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if (get_str('show') === 'deleted') {
    $where = ['c.deleted_at IS NOT NULL'];
}
if (get_str('show') === 'blocked') {
    $where[] = 'c.status = "blocked"';
}

$whereSql = implode(' AND ', $where);

$order = match ($sort) {
    'spent'  => 'c.total_spent DESC',
    'orders' => 'c.orders_count DESC',
    'name'   => 'c.full_name IS NULL, c.full_name',
    default  => 'c.created_at DESC',
};

if (get_str('export') === 'csv') {
    $rows = db_all(
        "SELECT c.email, c.full_name, c.phone, c.orders_count, c.total_spent,
                c.marketing_opt_in, c.created_at, c.last_login_at
         FROM customers c WHERE {$whereSql} ORDER BY {$order} LIMIT 5000",
        $params
    );
    admin_log('customers_export', 'customer', null, count($rows) . ' rows');
    admin_csv_out('customers-' . date('Y-m-d') . '.csv', $rows, [
        'email' => 'Email', 'full_name' => 'Name', 'phone' => 'Phone',
        'orders_count' => 'Orders', 'total_spent' => 'Lifetime value',
        'marketing_opt_in' => 'Marketing opt-in',
        'created_at' => 'Joined', 'last_login_at' => 'Last seen',
    ]);
}

$total = (int) db_val("SELECT COUNT(*) FROM customers c WHERE {$whereSql}", $params);
$pg    = admin_paginate($total, 30);

$rows = db_all(
    "SELECT c.* FROM customers c WHERE {$whereSql}
     ORDER BY {$order} LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

$stats = db_row(
    'SELECT COUNT(*) total,
            SUM(orders_count > 0) buyers,
            SUM(marketing_opt_in = 1) subscribed,
            COALESCE(SUM(total_spent),0) revenue
     FROM customers WHERE deleted_at IS NULL'
) ?: [];

$pageTitle    = 'Customers';
$adminSection = 'customers';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Customers</h1>
    <p><?= number_format($total) ?> account<?= $total === 1 ? '' : 's' ?></p>
  </div>
  <a class="btn btn-sm btn-soft" style="flex:none"
     href="<?= e(url('admin/customers.php?' . http_build_query($_GET + ['export' => 'csv']))) ?>">
    <?= icon('download', 15) ?> Export</a>
</div>

<div class="kpi-grid mb4">
  <div class="kpi"><p class="kpi__label">Accounts</p>
    <p class="kpi__value"><?= number_format((int) ($stats['total'] ?? 0)) ?></p></div>
  <div class="kpi"><p class="kpi__label">Have ordered</p>
    <p class="kpi__value"><?= number_format((int) ($stats['buyers'] ?? 0)) ?></p></div>
  <div class="kpi"><p class="kpi__label">Subscribed</p>
    <p class="kpi__value"><?= number_format((int) ($stats['subscribed'] ?? 0)) ?></p></div>
  <div class="kpi"><p class="kpi__label">Lifetime revenue</p>
    <p class="kpi__value"><?= e(money((float) ($stats['revenue'] ?? 0))) ?></p></div>
</div>

<div class="admin-toolbar">
  <form method="get" action="<?= e(url('admin/customers.php')) ?>" style="flex:1">
    <div class="search admin-search">
      <?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Email, name or phone">
    </div>
    <select class="select" name="sort" onchange="this.form.submit()" style="width:auto">
      <option value="">Newest first</option>
      <option value="spent"  <?= $sort === 'spent' ? 'selected' : '' ?>>Highest spend</option>
      <option value="orders" <?= $sort === 'orders' ? 'selected' : '' ?>>Most orders</option>
      <option value="name"   <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
    </select>
    <select class="select" name="show" onchange="this.form.submit()" style="width:auto">
      <option value="">Active</option>
      <option value="blocked" <?= get_str('show') === 'blocked' ? 'selected' : '' ?>>Blocked</option>
      <option value="deleted" <?= get_str('show') === 'deleted' ? 'selected' : '' ?>>Closed accounts</option>
    </select>
    <button class="btn btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="panel panel--flush">
  <?php if (!$rows): ?>
    <div class="admin-empty"><?= icon('user', 40) ?><p>No customers match.</p></div>
  <?php else: ?>
    <div class="scroll-x">
      <table class="admin-table">
        <thead><tr>
          <th>Customer</th><th>Phone</th><th class="num">Orders</th>
          <th class="num">Lifetime</th><th>Joined</th><th>Last seen</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td>
                <a href="<?= e(url('admin/customer-detail.php?id=' . (int) $c['id'])) ?>">
                  <strong><?= e((string) ($c['full_name'] ?: '—')) ?></strong></a>
                <br><span class="text-xs muted"><?= e((string) $c['email']) ?></span>
                <?php if ($c['status'] === 'blocked'): ?>
                  <span class="pill pill-bad">Blocked</span><?php endif; ?>
                <?php if ($c['deleted_at']): ?>
                  <span class="pill pill-muted">Closed</span><?php endif; ?>
              </td>
              <td class="text-xs"><?= e((string) ($c['phone'] ?: '—')) ?></td>
              <td class="num"><?= (int) $c['orders_count'] ?></td>
              <td class="num"><strong><?= e(money((float) $c['total_spent'])) ?></strong></td>
              <td class="text-xs"><?= e(fmt_date((string) $c['created_at'], 'j M y')) ?></td>
              <td class="text-xs muted"><?= $c['last_login_at']
                  ? e(time_ago((string) $c['last_login_at'])) : '—' ?></td>
              <td class="tight">
                <a class="btn-link" href="<?= e(url('admin/customer-detail.php?id=' . (int) $c['id'])) ?>">
                  Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($pg['pages'] > 1): ?>
  <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
  <?php pagination($pg['page'], $pg['pages']); ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
