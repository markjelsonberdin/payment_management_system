<?php
/**
 * SMS 2 - Top Navigation Bar
 */
require_once __DIR__ . '/authentication.php';
if (!isset($MODULES)) {
    require_once __DIR__ . '/../config/config.php';
}
$visibleModulesNav = getVisibleModules($MODULES);
?>
<nav class="navbar navbar-expand-lg navbar-dark sms-navbar fixed-top">
    <div class="container-fluid navbar-inner">

        <!-- Left: Toggle + Brand -->
        <div class="navbar-left d-flex align-items-center gap-2">
            <button class="btn btn-link text-white sidebar-toggle p-2" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>/dashboard/index.php">
                <i class="fas fa-graduation-cap"></i>
                <span class="d-none d-sm-inline"><?= htmlspecialchars(APP_SHORT_NAME) ?></span>
            </a>
        </div>

        <!-- Center: Global Search -->
        <div class="navbar-center">
            <div class="navbar-search position-relative">
                <i class="fas fa-search navbar-search-icon"></i>
                <input type="text" id="globalSearch" class="form-control navbar-search-input"
                       placeholder="Search modules and pages…"
                       autocomplete="off"
                       aria-label="Search modules and pages"
                       aria-haspopup="listbox"
                       aria-expanded="false">
                <button class="navbar-search-clear d-none" id="globalSearchClear" type="button" aria-label="Clear search">
                    <i class="fas fa-times"></i>
                </button>
                <div class="search-kbd-hint" aria-hidden="true">
                    <kbd>Ctrl</kbd><kbd>K</kbd>
                </div>
                <!-- Results dropdown -->
                <div class="navbar-search-dropdown" id="searchDropdown" role="listbox" aria-label="Search results">
                    <div class="search-empty" id="searchEmpty">
                        <i class="fas fa-search-minus"></i>
                        <span>No results found</span>
                    </div>
                    <ul class="search-results-list" id="searchResultsList"></ul>
                </div>
            </div>
        </div>

        <!-- Right: PH Clock + Theme + Messages + Notifications + User -->
        <div class="navbar-right d-flex align-items-center gap-2 gap-md-3">

            <!-- Philippine Standard Time (Asia/Manila) -->
            <?php
            $phClockMs = (int) round(microtime(true) * 1000);
            $phClockSeed = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('h:i:s A');
            ?>
            <time id="navbarPhClock"
                  class="navbar-ph-clock text-white"
                  datetime="<?= htmlspecialchars((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM)) ?>"
                  data-server-ms="<?= $phClockMs ?>"
                  title="Philippine Standard Time (UTC+8)"
                  aria-label="Philippine Standard Time">
                <?= htmlspecialchars($phClockSeed) ?>
            </time>

            <!-- Theme toggle -->
            <button type="button"
                    class="btn theme-toggle"
                    data-theme-toggle
                    aria-label="Switch theme"
                    title="Toggle theme">
                <i class="fas fa-moon theme-icon-moon" aria-hidden="true"></i>
                <i class="fas fa-sun theme-icon-sun" aria-hidden="true"></i>
            </button>

            <!-- Messages -->
            <div class="dropdown">
                <button class="btn btn-link text-white position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Messages">
                    <i class="fas fa-envelope"></i>
                    <span class="position-absolute badge rounded-pill bg-success notification-badge" style="top:2px;right:-2px;transform:none;">2</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:280px;">
                    <li><h6 class="dropdown-header">Messages</h6></li>
                    <li>
                        <a class="dropdown-item d-flex align-items-start gap-2 py-2" href="#">
                            <div class="navbar-msg-avatar">R</div>
                            <div class="navbar-msg-body">
                                <div class="navbar-msg-name">Registrar Office</div>
                                <div class="navbar-msg-text">New document request submitted...</div>
                                <div class="navbar-msg-time">2 mins ago</div>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item d-flex align-items-start gap-2 py-2" href="#">
                            <div class="navbar-msg-avatar">A</div>
                            <div class="navbar-msg-body">
                                <div class="navbar-msg-name">Admin</div>
                                <div class="navbar-msg-text">System maintenance scheduled...</div>
                                <div class="navbar-msg-time">1 hour ago</div>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item text-center text-primary py-2" href="#"><i class="fas fa-envelope-open me-1"></i>View all messages</a></li>
                </ul>
            </div>

            <!-- Notifications -->
            <div class="dropdown">
                <button class="btn btn-link text-white position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="position-absolute badge rounded-pill bg-danger notification-badge" style="top:2px;right:-2px;transform:none;">3</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><h6 class="dropdown-header">Notifications</h6></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-info-circle text-primary me-2"></i>Welcome to SMS 2</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-user-plus text-success me-2"></i>New enrollment pending</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-file-alt text-warning me-2"></i>Document request received</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-center text-primary" href="#">View all</a></li>
                </ul>
            </div>

            <!-- User Profile -->
            <div class="dropdown">
                <button class="btn btn-link text-white text-decoration-none dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle fa-lg"></i>
                    <span class="d-none d-md-inline"><?= htmlspecialchars(getCurrentUserName()) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><h6 class="dropdown-header"><?= htmlspecialchars(getCurrentUserRole()) ?></h6></li>
                    <?php
                    $navRole = getCurrentUserRoleKey();
                    if ($navRole === 'student') {
                        $profileHref = BASE_URL . '/modules/student-portal/pages/my-profile.php';
                        $profileLabel = 'My Profile';
                    } elseif ($navRole === 'admin') {
                        $profileHref = BASE_URL . '/account/profile.php';
                        $profileLabel = 'Account Settings';
                    } else {
                        $profileHref = BASE_URL . '/dashboard/index.php';
                        $profileLabel = 'My Profile';
                    }
                    ?>
                    <li>
                        <a class="dropdown-item" href="<?= htmlspecialchars($profileHref) ?>">
                            <i class="fas fa-user me-2"></i><?= htmlspecialchars($profileLabel) ?>
                        </a>
                    </li>
                    <?php if ($navRole === 'admin'): ?>
                    <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>/account/profile.php?tab=security">
                            <i class="fas fa-key me-2"></i>Login Security
                        </a>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Appearance</h6></li>
                    <li>
                        <button type="button" class="dropdown-item" data-theme-set="light">
                            <i class="fas fa-sun me-2"></i>Light mode
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" data-theme-set="dark">
                            <i class="fas fa-moon me-2"></i>Dark mode
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/login/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

    </div>
</nav>

<script>
/* Global Search Index — built from PHP $MODULES visible to current user */
window.SMS2_SEARCH_INDEX = (function() {
    var base = '<?= BASE_URL ?>';
    var items = [];
    <?php foreach ($visibleModulesNav as $navModuleKey => $module): ?>
    items.push({type:'module',label:<?= json_encode($module['label']) ?>,icon:<?= json_encode($module['icon']) ?>,url:base+'/modules/<?= $navModuleKey ?>/index.php',keywords:<?= json_encode(strtolower($module['label'])) ?>});
    <?php foreach ($module['pages'] as $page): ?>
    items.push({type:'page',label:<?= json_encode($page['title']) ?>,parent:<?= json_encode($module['label']) ?>,icon:<?= json_encode($module['icon']) ?>,url:base+'/modules/<?= $navModuleKey ?>/pages/<?= $page['slug'] ?>.php',keywords:<?= json_encode(strtolower($page['title'].' '.$module['label'])) ?>});
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php unset($navModuleKey, $module, $page); ?>    return items;
})();
</script>
