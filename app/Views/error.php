<?php /** @var int $code @var string $message */ ?>
<div class="os-empty">
    <div class="display-4 text-muted"><?= e($code) ?></div>
    <div class="os-empty-title"><?= e($message) ?></div>
    <a class="btn btn-primary btn-sm mt-3" href="<?= e(url('/')) ?>">Back to dashboard</a>
</div>
