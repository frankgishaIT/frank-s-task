<?php
/**
 * Reusable modal header
 * Expects: $modal_icon, $modal_title, $modal_subtitle
 * Optional: $modal_close_url (defaults to index.php)
 */
$modal_close_url = $modal_close_url ?? 'index.php';
?>
<div class="rm-modal-header">
    <div class="d-flex align-items-start gap-3">
        <div class="rm-icon">
            <i class="bi <?= htmlspecialchars($modal_icon, ENT_QUOTES, 'UTF-8'); ?> text-primary fs-2"></i>
        </div>
        <div>
            <h4 class="fw-bold mb-1"><?= htmlspecialchars($modal_title, ENT_QUOTES, 'UTF-8'); ?></h4>
            <p class="text-muted mb-0"><?= htmlspecialchars($modal_subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
    <a href="<?= htmlspecialchars($modal_close_url, ENT_QUOTES, 'UTF-8'); ?>" class="rm-modal-close">
        <i class="bi bi-x-lg"></i>
    </a>
</div>