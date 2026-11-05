<?php
/** Expects $result (page,pages,total,perPage) and $query (current query params). */
if (($result['pages'] ?? 1) > 1):
    $mk = static function (int $page) use ($query): string {
        $p = array_filter((array) $query, 'is_string');
        $p['page'] = (string) $page;
        return '?' . http_build_query($p);
    };
    $cur = (int) $result['page'];
    $last = (int) $result['pages'];
    $from = max(1, $cur - 2);
    $to = min($last, $cur + 2);
    ?>
    <nav class="px-3 py-3 border-top d-flex justify-content-center" aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $cur <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($mk(max(1, $cur - 1))) ?>">&laquo;</a></li>
            <?php if ($from > 1): ?><li class="page-item"><a class="page-link" href="<?= e($mk(1)) ?>">1</a></li><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php for ($i = $from; $i <= $to; $i++): ?>
                <li class="page-item<?= $i === $cur ? ' active' : '' ?>"><a class="page-link" href="<?= e($mk($i)) ?>"><?= e($i) ?></a></li>
            <?php endfor; ?>
            <?php if ($to < $last): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><li class="page-item"><a class="page-link" href="<?= e($mk($last)) ?>"><?= e($last) ?></a></li><?php endif; ?>
            <li class="page-item<?= $cur >= $last ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($mk(min($last, $cur + 1))) ?>">&raquo;</a></li>
        </ul>
    </nav>
<?php endif; ?>
