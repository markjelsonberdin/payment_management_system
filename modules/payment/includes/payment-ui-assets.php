<?php
/**
 * Payment UI asset helpers — register CSS for <head> via $extraHeadCss.
 * Display/layout only. Does not change financial behavior.
 */

if (!function_exists('paymentUiAssetUrl')) {
    function paymentUiAssetUrl(string $relativePath): string
    {
        return rtrim(BASE_URL, '/') . '/modules/payment/assets/' . ltrim($relativePath, '/');
    }
}

if (!function_exists('paymentUiRegisterCss')) {
    /**
     * @param list<string> $relativePaths Paths under modules/payment/assets/
     */
    function paymentUiRegisterCss(array $relativePaths): void
    {
        global $extraHeadCss;
        if (!isset($extraHeadCss) || !is_array($extraHeadCss)) {
            $extraHeadCss = [];
        }
        foreach ($relativePaths as $relativePath) {
            $url = paymentUiAssetUrl($relativePath);
            if (!in_array($url, $extraHeadCss, true)) {
                $extraHeadCss[] = $url;
            }
        }
    }
}

if (!function_exists('paymentUiUseSharedCss')) {
    /**
     * @param list<string> $moduleCss Extra module stylesheets under assets/css/
     */
    function paymentUiUseSharedCss(array $moduleCss = [], bool $tables = false, bool $period = false): void
    {
        $files = [
            'css/payment-base.css?v=1',
            'css/payment-components.css?v=3',
            'css/payment-utilities.css?v=1',
        ];
        if ($tables) {
            $files[] = 'css/payment-operational-tables.css?v=2';
        }
        if ($period) {
            $files[] = 'css/reporting-period-controls.css?v=1';
        }
        foreach ($moduleCss as $file) {
            $files[] = ltrim($file, '/');
        }
        paymentUiRegisterCss($files);
    }
}
