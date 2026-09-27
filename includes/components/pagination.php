<?php
/**
 * Pagination.
 *
 * Renders real <a href> links so the grid still paginates with JavaScript
 * disabled, and preserves every active filter in the query string.
 */
declare(strict_types=1);

/** Current URL with `page` swapped (omitted for page 1, keeping URLs clean). */
function paged_url(int $page): string
{
    $q = $_GET;
    if ($page <= 1) {
        unset($q['page']);
    } else {
        $q['page'] = $page;
    }
    $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $qs   = http_build_query($q);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Collapsed page list: 1 … 4 5 6 … 20
 * @return array<int|string>
 */
function pagination_range(int $current, int $total, int $edge = 1, int $around = 1): array
{
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [];
    for ($i = 1; $i <= $total; $i++) {
        $isEdge   = $i <= $edge || $i > $total - $edge;
        $isAround = abs($i - $current) <= $around;
        if ($isEdge || $isAround) {
            $pages[] = $i;
        }
    }

    $out  = [];
    $prev = 0;
    foreach ($pages as $p) {
        if ($prev && $p - $prev > 1) {
            $out[] = '…';
        }
        $out[] = $p;
        $prev  = $p;
    }
    return $out;
}

function pagination(int $current, int $total): void
{
    if ($total < 2) {
        return;
    }
    ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($current > 1): ?>
        <a href="<?= e(paged_url($current - 1)) ?>" rel="prev" aria-label="Previous page">
          <?= icon('arrow-left', 15) ?>
        </a>
      <?php endif; ?>

      <?php foreach (pagination_range($current, $total) as $p): ?>
        <?php if ($p === '…'): ?>
          <span class="gap" aria-hidden="true">…</span>
        <?php elseif ((int) $p === $current): ?>
          <span aria-current="page"><?= (int) $p ?></span>
        <?php else: ?>
          <a href="<?= e(paged_url((int) $p)) ?>" aria-label="Page <?= (int) $p ?>"><?= (int) $p ?></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($current < $total): ?>
        <a href="<?= e(paged_url($current + 1)) ?>" rel="next" aria-label="Next page">
          <?= icon('arrow-right', 15) ?>
        </a>
      <?php endif; ?>
    </nav>
    <?php
}
