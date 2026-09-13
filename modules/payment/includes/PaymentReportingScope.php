<?php

/**
 * Canonical predicates for official financial reporting.
 *
 * TEST and legacy UNKNOWN online attempts remain visible in audit/history flows,
 * but they must never contribute to production collection totals.
 */
final class PaymentReportingScope
{
    public static function officialCondition(string $alias = ''): string
    {
        if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid SQL table alias');
        }

        $prefix = $alias === '' ? '' : $alias . '.';

        return $prefix . "payment_status = 'Verified'"
            . ' AND (' . $prefix . "transaction_type <> 'Online'"
            . ' OR ' . $prefix . "gateway_environment = 'live')";
    }
}
