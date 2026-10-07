<?php
/**
 * SMS 2 - HTML Header
 * Expects: $pageTitle (string), optional $bodyClass (string)
 */
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/security.php';
smsSendSecurityHeaders();

$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="description" content="<?= e(APP_NAME) ?> - <?= e(INSTITUTION) ?>">
    <title><?= e($pageTitle) ?> | <?= e(APP_SHORT_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/images/bcp-logo-source.png">

    <!-- Apply theme before paint to avoid flash of wrong theme.
         Also set inline background so there's no white flash while
         Bootstrap and our CSS are still loading. -->
    <script>
    (function () {
        var DARK_BG  = '#0b1224';
        var LIGHT_BG = '#eef2f9';
        var LOGIN_BG = '#071c48';
        var isLoginPage = <?= json_encode(strpos(' ' . $bodyClass . ' ', ' login-page ') !== false) ?>;
        try {
            var forced = <?= json_encode(isset($forceTheme) && in_array($forceTheme, ['light', 'dark'], true) ? $forceTheme : '') ?>;
            var t = forced || localStorage.getItem('sms2-theme');
            if (t !== 'dark' && t !== 'light') t = 'light';
            var root = document.documentElement;
            root.setAttribute('data-theme', t);
            root.style.colorScheme = isLoginPage ? 'light' : t;
            // Auth screens use navy — never flash light gray/white on refresh
            root.style.backgroundColor = isLoginPage ? LOGIN_BG : (t === 'dark' ? DARK_BG : LIGHT_BG);
            if (forced) {
                root.setAttribute('data-theme-locked', '1');
            }
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
            document.documentElement.style.backgroundColor = isLoginPage ? LOGIN_BG : LIGHT_BG;
        }
    })();
    </script>

    <!-- Local vendor assets (offline-safe; no CDN DNS required) -->
    <link href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/vendor/fonts/inter.css" rel="stylesheet">
    <!-- Tabler Icons (used by Kenneth's UI in icons.php) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <?php
    // Payment pages opt in with $loadChartJs. Other SMS2 modules keep the existing default.
    $smsLoadChartJs = $loadChartJs ?? (($activeModule ?? '') !== 'payment');
    $smsLoadResearchCss = $loadResearchMonitoringCss ?? in_array((string) ($activeModule ?? ''), ['crad', 'faculty', 'student-portal', 'accreditation'], true);
    $smsExtraHeadCss = (isset($extraHeadCss) && is_array($extraHeadCss)) ? $extraHeadCss : [];
    if (($activeModule ?? '') === 'payment') {
        $paymentSharedCss = [
            BASE_URL . '/modules/payment/assets/css/payment-base.css?v=1',
            BASE_URL . '/modules/payment/assets/css/payment-components.css?v=2',
            BASE_URL . '/modules/payment/assets/css/payment-utilities.css?v=1',
            BASE_URL . '/modules/payment/assets/css/payment-operational-tables.css?v=2',
        ];
        $smsExtraHeadCss = array_values(array_unique(array_merge($paymentSharedCss, $smsExtraHeadCss)));
    }
    ?>
    <?php if ($smsLoadChartJs): ?>
    <script src="<?= BASE_URL ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
    <script>
    (function () {
        if (typeof Chart === 'undefined' || !Chart.prototype || Chart.prototype._smsUpdatePatched) return;
        var original = Chart.prototype.update;
        if (typeof original !== 'function') return;
        Chart.prototype.update = function () {
            try {
                if (!this || !this.ctx || this.destroyed) return this;
                return original.apply(this, arguments);
            } catch (err) {
                return this;
            }
        };
        Chart.prototype._smsUpdatePatched = true;
    })();
    </script>
    <?php endif; ?>
    <!-- SMS 2 Theme -->
    <link href="<?= BASE_URL ?>/assets/css/theme.css?v=3" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/layout.css?v=5" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/icons.css?v=1" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/responsive.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/dashboard-glass.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/sms-security-ui.css?v=19" rel="stylesheet">
    <?php if ($smsLoadResearchCss): ?>
    <link href="<?= BASE_URL ?>/assets/css/research-monitoring.css?v=1" rel="stylesheet">
    <?php endif; ?>
    <?php foreach ($smsExtraHeadCss as $smsHeadHref): ?>
    <link href="<?= htmlspecialchars((string) $smsHeadHref, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>"<?= strpos(' ' . $bodyClass . ' ', ' login-page ') !== false ? ' style="background:#071c48"' : '' ?>>
