<?php
/**
 * Policy / CMS page editor.
 *
 * The body is HTML written by a trusted admin. Tokens like {{store_name}} are
 * resolved at render time from Settings, so the shop's address and grievance
 * officer are edited in one place and appear correctly in all eight documents.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard('manager');

$TOKENS = [
    '{{store_name}}'   => 'Shop name',
    '{{legal_name}}'   => 'Registered business name',
    '{{address}}'      => 'Full postal address',
    '{{phone}}'        => 'Phone number',
    '{{email}}'        => 'Support email',
    '{{gstin}}'        => 'GSTIN',
    '{{grievance_name}}'  => 'Grievance officer name',
    '{{grievance_email}}' => 'Grievance officer email',
    '{{city}}'         => 'Jurisdiction city',
    '{{state}}'        => 'Jurisdiction state',
    '{{return_days}}'  => 'Return window in days',
    '{{dispatch_days}}'=> 'Dispatch time',
    '{{flat_rate}}'    => 'Flat delivery charge',
    '{{free_threshold}}' => 'Free delivery threshold',
];

$editId = get_int('edit');

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'save') {
        $title = post_str('title', 180);
        $body  = (string) ($_POST['body'] ?? '');

        if (mb_strlen($title) < 2) {
            flash('Give the page a title.', 'error');
        } else {
            if ($id > 0) {
                db_exec(
                    'UPDATE pages SET title = ?, body = ?, meta_title = ?, meta_description = ?,
                            is_active = ? WHERE id = ?',
                    [$title, mb_substr($body, 0, 200000),
                     post_str('meta_title', 180) ?: null,
                     post_str('meta_description', 320) ?: null,
                     empty($_POST['is_active']) ? 0 : 1, $id]
                );
                admin_log('page_update', 'page', $id, $title);
            } else {
                $slug = unique_slug(post_str('slug', 80) ?: $title, 'pages');
                $id = db_insert(
                    'INSERT INTO pages (slug, title, body, meta_title, meta_description, is_active)
                     VALUES (?,?,?,?,?,?)',
                    [$slug, $title, mb_substr($body, 0, 200000),
                     post_str('meta_title', 180) ?: null,
                     post_str('meta_description', 320) ?: null,
                     empty($_POST['is_active']) ? 0 : 1]
                );
                admin_log('page_create', 'page', $id, $title);
            }
            flash('Page saved.', 'success');
            redirect('admin/pages.php?edit=' . $id);
        }
    }

    if ($action === 'delete' && admin_can('owner')) {
        db_exec('DELETE FROM pages WHERE id = ?', [$id]);
        admin_log('page_delete', 'page', $id);
        flash('Page deleted.', 'success');
        redirect('admin/pages.php');
    }
}

$pages = db_all('SELECT * FROM pages ORDER BY title');
$edit  = $editId > 0 ? db_row('SELECT * FROM pages WHERE id = ?', [$editId]) : null;

$pageTitle    = 'Policy pages';
$adminSection = 'pages';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Policy pages</h1>
    <p>The eight legal documents linked from every page footer.</p></div>
  <?php if (!$edit): ?>
    <a class="btn btn-sm btn-soft" href="<?= e(url('admin/pages.php?edit=-1')) ?>">
      <?= icon('plus', 15) ?> New page</a>
  <?php endif; ?>
</div>

<?php if ($edit || $editId === -1): ?>
  <p class="mb4"><a class="text-sm" href="<?= e(url('admin/pages.php')) ?>">
    <?= icon('arrow-left', 13) ?> All pages</a></p>

  <form method="post" action="<?= e(url('admin/pages.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

    <div class="admin-cols">
      <div class="min0">
        <div class="panel">
          <h2><?= $edit ? 'Edit page' : 'New page' ?></h2>

          <div class="field">
            <label class="label" for="title">Title</label>
            <input class="input" id="title" name="title" required
                   value="<?= e((string) ($edit['title'] ?? '')) ?>">
          </div>

          <?php if (!$edit): ?>
            <div class="field">
              <label class="label" for="slug">URL</label>
              <div class="input-group">
                <span class="addon">/</span>
                <input class="input" id="slug" name="slug" placeholder="shipping-policy">
              </div>
            </div>
          <?php else: ?>
            <p class="text-sm muted">
              URL: <code>/<?= e((string) $edit['slug']) ?></code> ·
              <a href="<?= e(url((string) $edit['slug'])) ?>" target="_blank" rel="noopener">
                View <?= icon('external', 12) ?></a>
            </p>
          <?php endif; ?>

          <div class="field mb0">
            <label class="label" for="body">Content</label>
            <textarea class="textarea" id="body" name="body" rows="30" spellcheck="true"
                      style="font:13px/1.7 ui-monospace,Consolas,monospace"><?= e((string) ($edit['body'] ?? '')) ?></textarea>
            <span class="help">
              Plain HTML — <code>&lt;h2&gt;</code>, <code>&lt;p&gt;</code>,
              <code>&lt;ul&gt;&lt;li&gt;</code>, <code>&lt;strong&gt;</code>,
              <code>&lt;a href&gt;</code>. Use the tokens on the right instead of typing your
              address, so it stays correct everywhere.
            </span>
          </div>
        </div>

        <div class="panel">
          <h2>Search engine listing</h2>
          <div class="field">
            <label class="label" for="meta_title">Page title</label>
            <input class="input" id="meta_title" name="meta_title"
                   value="<?= e((string) ($edit['meta_title'] ?? '')) ?>">
          </div>
          <div class="field mb0">
            <label class="label" for="meta_description">Meta description</label>
            <textarea class="textarea" id="meta_description" name="meta_description" rows="2"
                      maxlength="320" style="min-height:64px"><?= e((string) ($edit['meta_description'] ?? '')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="admin-cols__side">
        <div class="panel">
          <h2>Publish</h2>
          <label class="check">
            <input type="checkbox" name="is_active" value="1"
                   <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span>Visible on the site</span>
          </label>
          <button class="btn btn-block mt4" type="submit">Save page</button>
          <?php if ($edit): ?>
            <p class="text-xs muted mt3 mb0">
              Last updated <?= e(fmt_datetime((string) $edit['updated_at'])) ?>
            </p>
          <?php endif; ?>
        </div>

        <div class="panel">
          <h2>Tokens you can use</h2>
          <p class="text-sm soft">
            These are replaced with the live value from Settings when the page is shown.
          </p>
          <table class="admin-table" style="min-width:0;font-size:var(--fs-xs)">
            <tbody>
              <?php foreach ($TOKENS as $tok => $label): ?>
                <tr>
                  <td style="padding:6px 4px"><code><?= e($tok) ?></code></td>
                  <td style="padding:6px 4px" class="muted"><?= e($label) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="panel" style="background:var(--warning-tint);border-color:#EFDCB6">
          <h2>Before you rewrite these</h2>
          <p class="text-sm mb0">
            The shipped text is aligned to the Consumer Protection (E-Commerce) Rules 2020 and
            the DPDP Act 2023. Edit the tone freely, but have a lawyer check anything you
            change about returns, refunds, jurisdiction or data handling.
          </p>
        </div>

        <?php if ($edit && admin_can('owner')): ?>
          <div class="panel" style="border-color:#F0C9C5">
            <h2 style="color:var(--danger)">Delete</h2>
            <p class="text-sm soft">The footer links to these pages. Deleting one leaves a 404.</p>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete"
                    formnovalidate onclick="return confirm('Delete this page?')">Delete page</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </form>

<?php else: ?>
  <div class="panel panel--flush">
    <div class="scroll-x">
      <table class="admin-table" style="min-width:560px">
        <thead><tr><th>Page</th><th>URL</th><th>Updated</th><th>State</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($pages as $p): ?>
            <tr>
              <td><strong><?= e((string) $p['title']) ?></strong></td>
              <td class="text-xs muted">/<?= e((string) $p['slug']) ?></td>
              <td class="text-xs"><?= e(fmt_date((string) $p['updated_at'], 'j M y')) ?></td>
              <td><?= (int) $p['is_active'] === 1
                    ? '<span class="pill pill-ok">Live</span>'
                    : '<span class="pill pill-muted">Hidden</span>' ?></td>
              <td class="tight">
                <div class="row gap2">
                  <a class="btn-link" href="<?= e(url('admin/pages.php?edit=' . (int) $p['id'])) ?>">Edit</a>
                  <a class="btn-link" href="<?= e(url((string) $p['slug'])) ?>"
                     target="_blank" rel="noopener">View</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
