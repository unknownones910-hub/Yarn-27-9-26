<?php
/**
 * Categories — the craft / fibre / weight taxonomies the shop filters by.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/upload.php';

$admin = admin_guard();

$editId = get_int('edit');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'delete' && admin_can('manager')) {
        $used = (int) db_val('SELECT COUNT(*) FROM product_categories WHERE category_id = ?', [$id]);
        if ($used > 0) {
            flash('That category still has ' . $used . ' product'
                . ($used === 1 ? '' : 's') . ' in it. Move them first.', 'warn');
        } else {
            $img = db_val('SELECT image FROM categories WHERE id = ?', [$id]);
            upload_delete($img ? (string) $img : null);
            db_exec('DELETE FROM categories WHERE id = ?', [$id]);
            admin_log('category_delete', 'category', $id);
            flash('Category deleted.', 'success');
        }
        redirect('admin/categories.php');
    }

    if ($action === 'save') {
        $name = post_str('name', 100);
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Give the category a name.';
        }

        $kind = post_str('kind', 20);
        if (!in_array($kind, ['craft', 'fiber', 'weight', 'other'], true)) {
            $kind = 'other';
        }

        if (!$errors) {
            $slug = post_str('slug', 120) ?: $name;

            $row = [
                'name'             => $name,
                'kind'             => $kind,
                'description'      => post_str('description', 2000) ?: null,
                'meta_title'       => post_str('meta_title', 180) ?: null,
                'meta_description' => post_str('meta_description', 320) ?: null,
                'sort_order'       => post_int('sort_order'),
                'is_active'        => empty($_POST['is_active']) ? 0 : 1,
            ];

            if ($id > 0) {
                $row['slug'] = unique_slug($slug, 'categories', $id);
                $set = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($row)));
                db_exec("UPDATE categories SET {$set} WHERE id = ?",
                        array_merge(array_values($row), [$id]));
                admin_log('category_update', 'category', $id, $name);
            } else {
                $row['slug'] = unique_slug($slug, 'categories');
                $cols = implode(',', array_keys($row));
                $ph   = implode(',', array_fill(0, count($row), '?'));
                $id = db_insert("INSERT INTO categories ({$cols}) VALUES ({$ph})", array_values($row));
                admin_log('category_create', 'category', $id, $name);
            }

            if (!empty($_FILES['image']['name'])) {
                $res = upload_image($_FILES['image'], 'banners');
                if ($res['ok']) {
                    $old = db_val('SELECT image FROM categories WHERE id = ?', [$id]);
                    upload_delete($old ? (string) $old : null);
                    db_exec('UPDATE categories SET image = ? WHERE id = ?', [$res['path'], $id]);
                } else {
                    flash('The image was not saved: ' . $res['error'], 'warn');
                }
            }

            flash('Category saved.', 'success');
            redirect('admin/categories.php');
        }
        $editId = $id ?: -1;
    }
}

$cats = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM product_categories pc WHERE pc.category_id = c.id) products
     FROM categories c ORDER BY c.kind, c.sort_order, c.name'
);

$edit = $editId > 0 ? db_row('SELECT * FROM categories WHERE id = ?', [$editId]) : null;
$ev   = static fn(string $k, $d = '') => e((string) ($_POST[$k] ?? $edit[$k] ?? $d));

$byKind = [];
foreach ($cats as $c) {
    $byKind[$c['kind']][] = $c;
}

$pageTitle    = 'Categories';
$adminSection = 'categories';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Categories</h1>
    <p>A yarn can sit in several at once — craft, fibre and weight.</p></div>
  <?php if ($editId === 0): ?>
    <a class="btn btn-sm" href="<?= e(url('admin/categories.php?edit=-1')) ?>">
      <?= icon('plus', 15) ?> New category</a>
  <?php endif; ?>
</div>

<?php if ($editId !== 0): ?>
  <div class="panel">
    <h2><?= $edit ? 'Edit ' . e((string) $edit['name']) : 'New category' ?></h2>
    <?php if ($errors): ?>
      <div class="alert alert-error mb4"><?= icon('alert', 18) ?>
        <div><?= e(implode(' ', $errors)) ?></div></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/categories.php')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

      <div class="field-row field-row--3">
        <div class="field">
          <label class="label" for="name">Name <span class="req">*</span> <?= help_tip('What customers see in the menu and on the category page, e.g. Cotton Yarn.', 'Name') ?></label>
          <input class="input" id="name" name="name" required data-slug-source value="<?= $ev('name') ?>">
        </div>
        <div class="field">
          <label class="label" for="kind">Group <?= help_tip('Which menu group this sits under, so the navigation stays organised as you add more categories.', 'Group') ?></label>
          <select class="select" id="kind" name="kind">
            <?php foreach (['craft' => 'By craft', 'fiber' => 'By fibre',
                            'weight' => 'By weight', 'other' => 'Other'] as $k => $l): ?>
              <option value="<?= e($k) ?>"
                      <?= ($_POST['kind'] ?? $edit['kind'] ?? '') === $k ? 'selected' : '' ?>>
                <?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="sort_order">Sort order <?= help_tip('Position in the list. Lower numbers come first. Ties fall back to alphabetical.', 'Sort order') ?></label>
          <input class="input" id="sort_order" name="sort_order" type="number"
                 value="<?= $ev('sort_order', '0') ?>">
        </div>
      </div>

      <div class="field">
        <label class="label" for="slug">URL <?= help_tip('The web address, e.g. cotton-yarn. Changing it after launch breaks existing links and any search rankings.', 'URL') ?></label>
        <div class="input-group">
          <span class="addon">/category/</span>
          <input class="input" id="slug" name="slug" data-slug-target value="<?= $ev('slug') ?>">
        </div>
      </div>

      <div class="field">
        <label class="label" for="description">Intro text <?= help_tip('A note for you, not the customer. It only appears in this admin list so you remember what a code was for.', 'Intro text') ?></label>
        <textarea class="textarea" id="description" name="description" rows="3"><?= $ev('description') ?></textarea>
        <span class="help">Shown above the product grid, and it is what search engines read.</span>
      </div>

      <div class="field">
        <label class="label" for="image">Tile image <?= help_tip('Shown on the homepage Shop by craft and Shop by fibre tiles. A square crop works best; without one the tile uses a placeholder.', 'Tile image') ?></label>
        <input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
        <span class="help">This is the picture on the homepage tile for this category. Square, at least 400&times;400.</span>
        <?php if ($edit && $edit['image']): ?>
          <img src="<?= e(upload_url((string) $edit['image'])) ?>" alt="" width="120" height="120"
               style="margin-top:8px;border-radius:8px;object-fit:cover">
        <?php endif; ?>
      </div>

      <div class="field-row field-row--2">
        <div class="field">
          <label class="label" for="meta_title">Page title <?= help_tip('The clickable headline in Google results. Around 60 characters. Leave blank to use the category name.', 'Page title') ?></label>
          <input class="input" id="meta_title" name="meta_title" value="<?= $ev('meta_title') ?>">
        </div>
        <div class="field">
          <label class="label" for="meta_description">Meta description <?= help_tip('The grey summary under the headline in Google. Around 155 characters.', 'Meta description') ?></label>
          <input class="input" id="meta_description" name="meta_description"
                 value="<?= $ev('meta_description') ?>">
        </div>
      </div>

      <label class="check">
        <input type="checkbox" name="is_active" value="1"
               <?= ($_POST['is_active'] ?? $edit['is_active'] ?? 1) ? 'checked' : '' ?>>
        <span>Show in the shop</span>
      </label>

      <div class="row mt4">
        <button class="btn" type="submit">Save category</button>
        <a class="btn btn-soft" href="<?= e(url('admin/categories.php')) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php foreach (['craft' => 'By craft', 'fiber' => 'By fibre', 'weight' => 'By weight',
                'other' => 'Other'] as $kind => $label): ?>
  <?php if (empty($byKind[$kind])) continue; ?>
  <div class="panel panel--flush">
    <h2><?= e($label) ?></h2>
    <div class="scroll-x">
      <table class="admin-table" style="min-width:560px">
        <thead><tr><th>Name</th><th>URL</th><th class="num">Products</th>
          <th class="num">Order</th><th>State</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($byKind[$kind] as $c): ?>
            <tr>
              <td>
                <div class="row gap2" style="align-items:center">
                  <?php if (!empty($c['image'])): ?>
                    <img src="<?= e(upload_url((string) $c['image'])) ?>" alt="" width="40" height="40"
                         style="width:40px;height:40px;object-fit:cover;border-radius:8px;flex:none">
                  <?php endif; ?>
                  <strong class="min0"><?= e((string) $c['name']) ?></strong>
                </div>
              </td>
              <td class="text-xs muted">/category/<?= e((string) $c['slug']) ?></td>
              <td class="num"><?= (int) $c['products'] ?></td>
              <td class="num"><?= (int) $c['sort_order'] ?></td>
              <td><?= (int) $c['is_active'] === 1
                    ? '<span class="pill pill-ok">Live</span>'
                    : '<span class="pill pill-muted">Hidden</span>' ?></td>
              <td class="tight">
                <div class="row gap2">
                  <a class="btn-link" href="<?= e(url('admin/categories.php?edit=' . (int) $c['id'])) ?>">
                    Edit</a>
                  <?php if (admin_can('manager') && (int) $c['products'] === 0): ?>
                    <form method="post" action="<?= e(url('admin/categories.php')) ?>"
                          data-confirm="Delete this category?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="btn-link is-danger" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
