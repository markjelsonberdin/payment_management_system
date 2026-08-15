<?php
/**
 * Shared module overview button grid
 * Expects: $activeModule, $MODULES, $breadcrumbs, optional $moduleIntro
 */
require_once __DIR__ . '/nav-icons.php';

if (!isset($MODULES[$activeModule])) {
    return;
}

$moduleMeta = $MODULES[$activeModule];
$moduleLabel = $moduleMeta['label'] ?? 'Module';
$moduleIcon = $moduleMeta['icon'] ?? 'fa-th-large';
$moduleIntro = $moduleIntro ?? 'Select a submodule below to get started.';

// Role-accurate report cards for Reports & Analytics.
if ($activeModule === 'reports-analytics') {
    require_once __DIR__ . '/reports-catalog.php';
    $moduleMeta['pages'] = array_map(
        static fn(array $report): array => [
            'slug'  => $report['slug'],
            'title' => $report['title'],
        ],
        smsReportsForRole()
    );
    $moduleIntro = 'Only reports tied to your assigned office modules are listed below.';
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="page-header">
    <h1><i class="fas <?= htmlspecialchars($moduleIcon) ?> text-sms-primary me-2"></i><?= htmlspecialchars($moduleLabel) ?></h1>
    <p><?= htmlspecialchars($moduleIntro) ?></p>
</div>

<div class="row g-3 module-button-grid">
    <?php foreach ($moduleMeta['pages'] as $page): ?>
        <?php
        $href = BASE_URL . '/modules/' . $activeModule . '/pages/' . $page['slug'] . '.php';
        $icon = smsNavPageIcon($page['slug']);
        ?>
        <div class="col-md-6 col-lg-4">
            <a href="<?= htmlspecialchars($href) ?>" class="text-decoration-none d-block h-100">
                <div class="card module-card hover-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="card-icon"><i class="fas <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i></div>
                        <div class="min-w-0">
                            <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($page['title']) ?></h6>
                            <small class="text-muted"><?= $activeModule === 'reports-analytics' ? 'Open report process' : 'Open submodule' ?></small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
