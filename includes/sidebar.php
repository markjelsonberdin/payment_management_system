<?php
/**
 * SMS 2 - Sidebar Navigation
 * Expects: optional $activeModule (string), optional $activePage (string)
 */
if (!isset($MODULES)) {
    require_once __DIR__ . '/../config/config.php';
}
require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/module-controls.php';
require_once __DIR__ . '/nav-icons.php';

$activeModule = $activeModule ?? '';
$activePage   = $activePage ?? '';
$roleKey = getCurrentUserRoleKey();
$isStudentPortal = $roleKey === 'student';
$visibleModules = getVisibleModules($MODULES);


// ── For students: check if Research Forum is paid ───────────────────────────
$researchForumPaid = false;
if ($isStudentPortal) {
    // If student-portal-page.php already computed this, use it.
    // Otherwise check independently from the payment data source.
    if (isset($researchForumPaid) && $researchForumPaid === true) {
        // already set by student-portal-page.php context
    } else {
        // Standalone check: mirror the same transaction list.
        // In production, replace with a real DB query against payment table.
        $sidebarPayments = [
            ['description' => 'Tuition Down Payment',  'status' => 'Paid'],
            ['description' => 'Registration Fee',       'status' => 'Paid'],
            ['description' => 'Laboratory Fee',         'status' => 'Paid'],
            ['description' => 'Research Forum',         'status' => 'Paid'],
        ];
        foreach ($sidebarPayments as $txn) {
            if (
                stripos($txn['description'], 'Research Forum') !== false &&
                strtolower($txn['status']) === 'paid'
            ) {
                $researchForumPaid = true;
                break;
            }
        }
    }
}

$studentNavGroups = [
    'Student Information' => [
        ['slug' => 'my-profile',  'href' => BASE_URL . '/modules/student-portal/pages/my-profile.php',  'icon' => 'fa-user',    'label' => 'My Profile',  'locked' => false],
        ['slug' => 'student-id',  'href' => BASE_URL . '/modules/student-portal/pages/student-id.php',  'icon' => 'fa-id-card', 'label' => 'Student ID',  'locked' => false],
    ],
  'Financial' => [
        ['slug' => 'account-balance',  'href' => BASE_URL . '/modules/student-portal/pages/account-balance.php',  'icon' => 'fa-wallet',  'label' => 'Account Balance',  'locked' => false],
        ['slug' => 'payment-history',  'href' => BASE_URL . '/modules/student-portal/pages/payment-history.php',  'icon' => 'fa-receipt', 'label' => 'Payment History',  'locked' => false],
        ['slug' => 'student-concern-portal',  'href' => BASE_URL . '/modules/student-portal/pages/student-concern-portal.php',  'icon' => 'fa-exclamation', 'label' => 'Payment Concern',  'locked' => false],
    ],
    'Academics' => [
        ['slug' => 'class-schedule',      'href' => BASE_URL . '/modules/student-portal/pages/class-schedule.php',      'icon' => 'fa-calendar-alt',        'label' => 'Class Schedule',       'locked' => false],
        ['slug' => 'academic-records',    'href' => BASE_URL . '/modules/student-portal/pages/academic-records.php',    'icon' => 'fa-file-alt',            'label' => 'Academic Records',     'locked' => false],
        ['slug' => 'subjects-professors', 'href' => BASE_URL . '/modules/student-portal/pages/subjects-professors.php', 'icon' => 'fa-chalkboard-teacher',  'label' => 'Subject & Professors', 'locked' => false],
        ['slug' => 'grades-portal',       'href' => BASE_URL . '/modules/student-portal/pages/grades-portal.php',       'icon' => 'fa-star-half-alt',       'label' => 'Grades Portal',        'locked' => false],
    ],
    'Research' => [
        ['slug' => 'research-proposal-submission', 'href' => BASE_URL . '/modules/student-portal/pages/research-proposal-submission.php', 'icon' => 'fa-flask',            'label' => 'Research Proposal', 'locked' => false],
        ['slug' => 'submit-documents',             'href' => BASE_URL . '/modules/student-portal/pages/submit-documents.php',             'icon' => 'fa-cloud-upload-alt', 'label' => 'Submit Documents',  'locked' => !$researchForumPaid],
    ],
    'System' => [
        ['slug' => 'security-settings', 'href' => BASE_URL . '/account/module-security.php?module=student_portal', 'icon' => 'fa-shield-alt', 'label' => 'Security Settings', 'locked' => false],
    ],
];
?>
<aside class="sms-sidebar" id="smsSidebar" aria-label="Main navigation">
    <nav class="sidebar-nav" id="smsSidebarAccordion">
        <ul class="nav flex-column">
            <?php if ($isStudentPortal): ?>
                <?php foreach ($studentNavGroups as $groupLabel => $groupItems): ?>
                    <li class="nav-item sidebar-group-label">
                        <span class="nav-link sidebar-group-heading"><?= htmlspecialchars($groupLabel) ?></span>
                    </li>
                    <?php foreach ($groupItems as $item): ?>
                        <?php
                        $isLocked  = !empty($item['locked']);
                        $linkClass = ($activeModule === 'student_portal' && $activePage === $item['slug']) ? 'active' : '';
                        if ($isLocked) { $linkClass .= ' nav-link-locked'; }
                        ?>
                        <li class="nav-item">
                            <?php if ($isLocked): ?>
                                <span class="nav-link sidebar-sub <?= $linkClass ?>"
                                      data-title="<?= htmlspecialchars($item['label']) ?> (Locked)"
                                      title="<?= htmlspecialchars($item['label']) ?> — Pay Research Forum to unlock"
                                      style="cursor:not-allowed;opacity:0.5;">
                                    <i class="fas fa-lock me-1" aria-hidden="true" style="font-size:0.75rem;"></i>
                                    <i class="fas <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                </span>
                            <?php else: ?>
                                <a class="nav-link sidebar-sub <?= $linkClass ?>"
                                   href="<?= htmlspecialchars($item['href']) ?>"
                                   data-title="<?= htmlspecialchars($item['label']) ?>"
                                   title="<?= htmlspecialchars($item['label']) ?>">
                                    <i class="fas <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeModule === 'dashboard' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/dashboard/index.php"
                       data-title="Dashboard"
                       title="Dashboard">
                        <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <?php foreach ($visibleModules as $navModuleKey => $module): ?>
                    <?php
                    $isModuleActive = ($activeModule === $navModuleKey);
                    $collapseId = 'sidebar-' . $navModuleKey;
                    $overviewUrl = BASE_URL . '/modules/' . $navModuleKey . '/index.php';
                    $moduleInMaint = smsIsModuleInMaintenance((string) $navModuleKey);
                    ?>
                    <li class="nav-item">
                        <a class="nav-link sidebar-parent <?= $isModuleActive ? 'active' : '' ?>"
                           data-bs-toggle="collapse"
                           href="#<?= $collapseId ?>"
                           role="button"
                           aria-expanded="<?= $isModuleActive ? 'true' : 'false' ?>"
                           aria-controls="<?= $collapseId ?>"
                           data-title="<?= htmlspecialchars($module['label']) ?>"
                           data-overview-url="<?= htmlspecialchars($overviewUrl) ?>"
                           title="<?= htmlspecialchars($module['label']) ?>">
                            <i class="fas <?= htmlspecialchars($module['icon']) ?>" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($module['label']) ?></span>
                            <?php if ($moduleInMaint): ?>
                                <span class="badge text-bg-warning ms-1" style="font-size:0.62rem;">Maint</span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-down ms-auto sidebar-chevron" aria-hidden="true"></i>
                        </a>
                        <div class="collapse <?= $isModuleActive ? 'show' : '' ?>"
                             id="<?= $collapseId ?>"
                             data-bs-parent="#smsSidebarAccordion">
                            <ul class="nav flex-column sidebar-submenu">
                                <li class="nav-item">
                                    <a class="nav-link sidebar-sub overview-link <?= ($isModuleActive && $activePage === '') ? 'active' : '' ?>"
                                       href="<?= htmlspecialchars($overviewUrl) ?>">
                                        <i class="fas fa-th-large" aria-hidden="true"></i>
                                        <span>Overview</span>
                                    </a>
                                </li>
                                <?php
                                // Check if module has grouped sidebar sections
                                $hasGroups = !empty($module['groups']) && is_array($module['groups']);
                                if ($hasGroups):
                                    // Build a lookup map from slug to page title
                                    $pageTitles = [];
                                    foreach ($module['pages'] as $p) {
                                        $pageTitles[$p['slug']] = $p['title'];
                                    }
                                    foreach ($module['groups'] as $groupLabel => $groupSlugs):
                                ?>
                                    <li class="nav-item sidebar-group-label">
                                        <span class="nav-link sidebar-group-heading"><?= htmlspecialchars($groupLabel) ?></span>
                                    </li>
                                    <?php foreach ($groupSlugs as $slug): ?>
                                        <?php
                                        if (!isset($pageTitles[$slug])) { continue; }
                                        $isPageActive = ($isModuleActive && $activePage === $slug);
                                        $pageHref = BASE_URL . '/modules/' . $navModuleKey . '/pages/' . $slug . '.php';
                                        ?>
                                        <li class="nav-item">
                                            <a class="nav-link sidebar-sub <?= $isPageActive ? 'active' : '' ?>"
                                               href="<?= htmlspecialchars($pageHref) ?>">
                                                <i class="fas <?= htmlspecialchars(smsNavPageIcon($slug)) ?>" aria-hidden="true"></i>
                                                <span><?= htmlspecialchars($pageTitles[$slug]) ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <?php foreach ($module['pages'] as $page): ?>
                                    <?php
                                    $isPageActive = ($isModuleActive && $activePage === $page['slug']);
                                    $pageHref = BASE_URL . '/modules/' . $navModuleKey . '/pages/' . $page['slug'] . '.php';
                                    // Module Security: keep CRAD/etc. focus when already inside a module.
                                    if ($navModuleKey === 'user-management' && $page['slug'] === 'module-security') {
                                        $secFocus = (string) ($_SESSION['um_sec_focus'] ?? '');
                                        if ($secFocus !== '' && ($activePage ?? '') === 'module-security' && empty($_GET['picker'])) {
                                            $pageHref .= '?focus=' . rawurlencode($secFocus);
                                        } else {
                                            $pageHref .= '?picker=1';
                                        }
                                    }
                                    ?>
                                    <li class="nav-item">
                                        <a class="nav-link sidebar-sub <?= $isPageActive ? 'active' : '' ?>"
                                           href="<?= htmlspecialchars($pageHref) ?>">
                                            <i class="fas <?= htmlspecialchars(smsNavPageIcon($page['slug'])) ?>" aria-hidden="true"></i>
                                            <span><?= htmlspecialchars($page['title']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                <?php
                                // Super Admin: Module Security stays under User Management only.
                                // Staff keep Security Settings on their own module sidebar.
                                if ($roleKey !== 'admin' && $navModuleKey !== 'user-management'):
                                ?>
                                <li class="nav-item sidebar-group-label">
                                    <span class="nav-link sidebar-group-heading">System</span>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link sidebar-sub <?= ($isModuleActive && $activePage === 'security-settings') ? 'active' : '' ?>"
                                       href="<?= BASE_URL ?>/account/module-security.php?module=<?= urlencode($navModuleKey) ?>">
                                        <i class="fas fa-shield-alt" aria-hidden="true"></i>
                                        <span>Security Settings</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php unset($navModuleKey, $module, $page, $isModuleActive, $collapseId, $overviewUrl, $pageHref, $isPageActive, $secFocus); ?>            <?php endif; ?>
        </ul>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
