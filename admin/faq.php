<?php
/**
 * FAQ editor. Every answer added here reduces a support email.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'save') {
        $q = post_str('question', 300);
        $a = post_str('answer', 4000);
        $c = post_str('category', 60) ?: 'General';

        if (mb_strlen($q) < 5 || mb_strlen($a) < 5) {
            flash('A question and an answer are both needed.', 'error');
        } else {
            if ($id > 0) {
                db_exec('UPDATE faqs SET category=?, question=?, answer=?, sort_order=?, is_active=?
                         WHERE id = ?',
                        [$c, $q, $a, post_int('sort_order'), empty($_POST['is_active']) ? 0 : 1, $id]);
                admin_log('faq_update', 'faq', $id);
            } else {
                $id = db_insert('INSERT INTO faqs (category, question, answer, sort_order, is_active)
                                 VALUES (?,?,?,?,?)',
                                [$c, $q, $a, post_int('sort_order'), empty($_POST['is_active']) ? 0 : 1]);
                admin_log('faq_create', 'faq', $id);
            }
            flash('Saved.', 'success');
        }
    }

    if ($action === 'delete') {
        db_exec('DELETE FROM faqs WHERE id = ?', [$id]);
        admin_log('faq_delete', 'faq', $id);
        flash('Question removed.', 'success');
    }

    if ($action === 'toggle') {
        db_exec('UPDATE faqs SET is_active = 1 - is_active WHERE id = ?', [$id]);
    }

    redirect('admin/faq.php' . (get_str('cat') !== '' ? '?cat=' . urlencode(get_str('cat')) : ''));
}

$editId = get_int('edit');
$edit   = $editId > 0 ? db_row('SELECT * FROM faqs WHERE id = ?', [$editId]) : null;

$faqs = db_all('SELECT * FROM faqs ORDER BY category, sort_order, id');
$grouped = [];
foreach ($faqs as $f) {
    $grouped[$f['category']][] = $f;
}

$pageTitle    = 'FAQs';
$adminSection = 'faq';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>FAQs</h1>
    <p><?= count($faqs) ?> questions across <?= count($grouped) ?> groups ·
      <a href="<?= e(url('faq.php')) ?>" target="_blank" rel="noopener">view the page
        <?= icon('external', 12) ?></a></p></div>
</div>

<div class="admin-cols">
  <div class="min0">
    <?php foreach ($grouped as $cat => $items): ?>
      <div class="panel panel--flush">
        <h2><?= e($cat) ?> <span class="muted">· <?= count($items) ?></span></h2>
        <div class="scroll-x">
          <table class="admin-table" style="min-width:520px">
            <thead><tr><th class="num">#</th><th>Question</th><th>State</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $f): ?>
                <tr>
                  <td class="num muted"><?= (int) $f['sort_order'] ?></td>
                  <td>
                    <strong><?= e((string) $f['question']) ?></strong><br>
                    <span class="text-xs muted"><?= e(str_excerpt((string) $f['answer'], 110)) ?></span>
                  </td>
                  <td><?= (int) $f['is_active'] === 1
                        ? '<span class="pill pill-ok">Live</span>'
                        : '<span class="pill pill-muted">Hidden</span>' ?></td>
                  <td class="tight">
                    <div class="row gap2">
                      <a class="btn-link" href="<?= e(url('admin/faq.php?edit=' . (int) $f['id'])) ?>">Edit</a>
                      <form method="post" action="<?= e(url('admin/faq.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button class="btn-link" type="submit">
                          <?= (int) $f['is_active'] === 1 ? 'Hide' : 'Show' ?></button>
                      </form>
                      <form method="post" action="<?= e(url('admin/faq.php')) ?>"
                            data-confirm="Delete this question?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button class="btn-link is-danger" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!$faqs): ?>
      <div class="panel"><div class="admin-empty">
        <?= icon('info', 40) ?><p>No questions yet — add the first one.</p></div></div>
    <?php endif; ?>
  </div>

  <div class="admin-cols__side">
    <div class="panel">
      <h2><?= $edit ? 'Edit question' : 'Add a question' ?></h2>
      <form method="post" action="<?= e(url('admin/faq.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

        <div class="field">
          <label class="label" for="category">Group</label>
          <input class="input" id="category" name="category" list="faq-cats"
                 value="<?= e((string) ($edit['category'] ?? '')) ?>" placeholder="Shipping">
          <datalist id="faq-cats">
            <?php foreach (array_keys($grouped) as $c): ?>
              <option value="<?= e($c) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="field">
          <label class="label" for="question">Question</label>
          <textarea class="textarea" id="question" name="question" rows="2" required
                    style="min-height:60px"><?= e((string) ($edit['question'] ?? '')) ?></textarea>
        </div>

        <div class="field">
          <label class="label" for="answer">Answer</label>
          <textarea class="textarea" id="answer" name="answer" rows="6"
                    required><?= e((string) ($edit['answer'] ?? '')) ?></textarea>
          <span class="help">Plain text. Line breaks are kept. Answer honestly — including
            when the answer is no.</span>
        </div>

        <div class="field">
          <label class="label" for="sort_order">Sort order</label>
          <input class="input" id="sort_order" name="sort_order" type="number"
                 style="max-width:120px" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
        </div>

        <label class="check">
          <input type="checkbox" name="is_active" value="1"
                 <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>>
          <span>Show on the FAQ page</span>
        </label>

        <div class="row mt4">
          <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Add question' ?></button>
          <?php if ($edit): ?>
            <a class="btn btn-soft" href="<?= e(url('admin/faq.php')) ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
