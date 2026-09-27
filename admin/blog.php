<?php
/**
 * Blog post editor.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/upload.php';

$admin = admin_guard();

$editId = get_int('edit');

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'delete' && admin_can('manager')) {
        $cover = db_val('SELECT cover FROM blog_posts WHERE id = ?', [$id]);
        upload_delete($cover ? (string) $cover : null);
        db_exec('DELETE FROM blog_posts WHERE id = ?', [$id]);
        admin_log('blog_delete', 'blog', $id);
        flash('Post deleted.', 'success');
        redirect('admin/blog.php');
    }

    if ($action === 'save') {
        $title = post_str('title', 200);

        if (mb_strlen($title) < 3) {
            flash('Give the post a title.', 'error');
            redirect('admin/blog.php?edit=' . ($id ?: -1));
        }

        $status    = post_str('status', 20) === 'published' ? 'published' : 'draft';
        $published = post_str('published_at', 20);
        $publishAt = $published !== '' ? date('Y-m-d H:i:s', (int) strtotime($published)) : null;
        if ($status === 'published' && $publishAt === null) {
            $publishAt = date('Y-m-d H:i:s');
        }

        $row = [
            'title'            => $title,
            'excerpt'          => post_str('excerpt', 400) ?: null,
            'body'             => mb_substr((string) ($_POST['body'] ?? ''), 0, 200000),
            'author'           => post_str('author', 80) ?: null,
            'tags'             => post_str('tags', 200) ?: null,
            'meta_title'       => post_str('meta_title', 180) ?: null,
            'meta_description' => post_str('meta_description', 320) ?: null,
            'status'           => $status,
            'published_at'     => $publishAt,
        ];

        if ($id > 0) {
            $set = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($row)));
            db_exec("UPDATE blog_posts SET {$set} WHERE id = ?",
                    array_merge(array_values($row), [$id]));
            admin_log('blog_update', 'blog', $id, $title);
        } else {
            $row = ['slug' => unique_slug(post_str('slug', 180) ?: $title, 'blog_posts')] + $row;
            $cols = implode(',', array_keys($row));
            $ph   = implode(',', array_fill(0, count($row), '?'));
            $id = db_insert("INSERT INTO blog_posts ({$cols}) VALUES ({$ph})", array_values($row));
            admin_log('blog_create', 'blog', $id, $title);
        }

        if (!empty($_FILES['cover']['name'])) {
            $res = upload_image($_FILES['cover'], 'blog');
            if ($res['ok']) {
                $old = db_val('SELECT cover FROM blog_posts WHERE id = ?', [$id]);
                upload_delete($old ? (string) $old : null);
                db_exec('UPDATE blog_posts SET cover = ? WHERE id = ?', [$res['path'], $id]);
            } else {
                flash('The cover image was not saved: ' . $res['error'], 'warn');
            }
        }

        flash('Post saved.', 'success');
        redirect('admin/blog.php?edit=' . $id);
    }
}

$posts = db_all('SELECT * FROM blog_posts ORDER BY COALESCE(published_at, created_at) DESC');
$edit  = $editId > 0 ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$editId]) : null;
$v     = static fn(string $k, $d = '') => e((string) ($edit[$k] ?? $d));

$pageTitle    = 'Blog';
$adminSection = 'blog';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Blog</h1>
    <p><?= count($posts) ?> post<?= count($posts) === 1 ? '' : 's' ?></p></div>
  <?php if (!$edit && $editId !== -1): ?>
    <a class="btn btn-sm" href="<?= e(url('admin/blog.php?edit=-1')) ?>">
      <?= icon('plus', 15) ?> New post</a>
  <?php endif; ?>
</div>

<?php if ($edit || $editId === -1): ?>
  <p class="mb4"><a class="text-sm" href="<?= e(url('admin/blog.php')) ?>">
    <?= icon('arrow-left', 13) ?> All posts</a></p>

  <form method="post" action="<?= e(url('admin/blog.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

    <div class="admin-cols">
      <div class="min0">
        <div class="panel">
          <h2><?= $edit ? 'Edit post' : 'New post' ?></h2>

          <div class="field">
            <label class="label" for="title">Title</label>
            <input class="input" id="title" name="title" required data-slug-source value="<?= $v('title') ?>">
          </div>

          <?php if (!$edit): ?>
            <div class="field">
              <label class="label" for="slug">URL</label>
              <div class="input-group">
                <span class="addon">/blog/</span>
                <input class="input" id="slug" name="slug" data-slug-target>
              </div>
            </div>
          <?php else: ?>
            <p class="text-sm muted">URL: <code>/blog/<?= e((string) $edit['slug']) ?></code>
              <?php if ($edit['status'] === 'published'): ?>
                · <a href="<?= e(url('blog/' . $edit['slug'])) ?>" target="_blank" rel="noopener">
                  View <?= icon('external', 12) ?></a>
              <?php endif; ?></p>
          <?php endif; ?>

          <div class="field">
            <label class="label" for="excerpt">Excerpt</label>
            <textarea class="textarea" id="excerpt" name="excerpt" rows="2"
                      style="min-height:64px"><?= $v('excerpt') ?></textarea>
            <span class="help">Shown on the blog index and in search results.</span>
          </div>

          <div class="field mb0">
            <label class="label" for="body">Post</label>
            <textarea class="textarea" id="body" name="body" rows="24" spellcheck="true"
                      style="font:13px/1.7 ui-monospace,Consolas,monospace"><?= $v('body') ?></textarea>
            <span class="help">
              Plain HTML: <code>&lt;h2&gt;</code>, <code>&lt;p&gt;</code>,
              <code>&lt;ul&gt;&lt;li&gt;</code>, <code>&lt;img src&gt;</code>,
              <code>&lt;a href&gt;</code>.
            </span>
          </div>
        </div>

        <div class="panel">
          <h2>Search engine listing</h2>
          <div class="field">
            <label class="label" for="meta_title">Page title</label>
            <input class="input" id="meta_title" name="meta_title" value="<?= $v('meta_title') ?>">
          </div>
          <div class="field mb0">
            <label class="label" for="meta_description">Meta description</label>
            <textarea class="textarea" id="meta_description" name="meta_description" rows="2"
                      maxlength="320" style="min-height:64px"><?= $v('meta_description') ?></textarea>
          </div>
        </div>
      </div>

      <div class="admin-cols__side">
        <div class="panel">
          <h2>Publish</h2>
          <div class="field">
            <label class="label" for="status">Status</label>
            <select class="select" id="status" name="status">
              <option value="draft"     <?= ($edit['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
              <option value="published" <?= ($edit['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
            </select>
          </div>
          <div class="field">
            <label class="label" for="published_at">Publish date</label>
            <input class="input" id="published_at" name="published_at" type="datetime-local"
                   value="<?= e(!empty($edit['published_at'])
                          ? date('Y-m-d\TH:i', strtotime((string) $edit['published_at'])) : '') ?>">
            <span class="help">Leave blank to publish now.</span>
          </div>
          <button class="btn btn-block" type="submit">Save post</button>
        </div>

        <div class="panel">
          <h2>Details</h2>
          <div class="field">
            <label class="label" for="author">Author</label>
            <input class="input" id="author" name="author"
                   value="<?= $v('author', (string) $admin['name']) ?>">
          </div>
          <div class="field mb0">
            <label class="label" for="tags">Tags</label>
            <input class="input" id="tags" name="tags" value="<?= $v('tags') ?>"
                   placeholder="Tutorial, Crochet">
            <span class="help">Comma separated. The first one shows on the card.</span>
          </div>
        </div>

        <div class="panel">
          <h2>Cover image</h2>
          <?php if ($edit && $edit['cover']): ?>
            <img src="<?= e(upload_url((string) $edit['cover'])) ?>" alt=""
                 style="width:100%;border-radius:var(--r-sm);margin-bottom:var(--s3)">
          <?php endif; ?>
          <input class="input" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
          <span class="help">Landscape works best — roughly 16:10.</span>
        </div>

        <?php if ($edit && admin_can('manager')): ?>
          <div class="panel" style="border-color:#F0C9C5">
            <h2 style="color:var(--danger)">Delete</h2>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete"
                    formnovalidate onclick="return confirm('Delete this post?')">Delete post</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </form>

<?php else: ?>
  <div class="panel panel--flush">
    <?php if (!$posts): ?>
      <div class="admin-empty">
        <?= icon('edit', 40) ?>
        <p>No posts yet.</p>
        <p class="text-xs">A tutorial that answers a common question is the best first post —
          it earns search traffic and cuts support email.</p>
        <a class="btn btn-sm mt3" href="<?= e(url('admin/blog.php?edit=-1')) ?>">Write the first one</a>
      </div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:560px">
          <thead><tr><th>Title</th><th>Tags</th><th>Published</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($posts as $p): ?>
              <tr>
                <td><strong><?= e((string) $p['title']) ?></strong>
                  <br><span class="text-xs muted">/blog/<?= e((string) $p['slug']) ?></span></td>
                <td class="text-xs muted"><?= e((string) $p['tags']) ?></td>
                <td class="text-xs"><?= $p['published_at']
                      ? e(fmt_date((string) $p['published_at'], 'j M y')) : '—' ?></td>
                <td><?= $p['status'] === 'published'
                      ? '<span class="pill pill-ok">Published</span>'
                      : '<span class="pill pill-muted">Draft</span>' ?></td>
                <td class="tight">
                  <a class="btn-link" href="<?= e(url('admin/blog.php?edit=' . (int) $p['id'])) ?>">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
