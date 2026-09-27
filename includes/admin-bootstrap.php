<?php
/**
 * First require in EVERY /admin page.
 *
 * Defines YN_ADMIN before bootstrap runs, which switches the session cookie to
 * YARNNEST_ADM. That separation is deliberate: the owner can be signed into
 * the shop as a customer and into the admin at the same time without one
 * clobbering the other, and a stolen shop session is not an admin session.
 */
declare(strict_types=1);

if (!defined('YN_ADMIN')) {
    define('YN_ADMIN', true);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';

/**
 * Gate a page. Call at the top of every admin screen except login.
 * Also enforces the optional IP allowlist.
 */
function admin_guard(string $minRole = 'staff'): array
{
    require YN_ROOT . '/config/admin-config.php';

    $ips = $ADMIN['ip_allowlist'] ?? [];
    if ($ips && !in_array(client_ip(), $ips, true)) {
        http_response_code(403);
        exit('Admin access is restricted to approved networks.');
    }

    return require_admin($minRole);
}

/** Pretty number for dashboard tiles: 1.2k, 14.5k. */
function admin_short_num(float $n): string
{
    if ($n >= 10000000) return round($n / 10000000, 1) . 'Cr';
    if ($n >= 100000)   return round($n / 100000, 1) . 'L';
    if ($n >= 1000)     return round($n / 1000, 1) . 'k';
    return (string) (int) $n;
}

/**
 * Send a result set to the browser as CSV.
 * @param array $rows    list of associative arrays
 * @param array $headers column key => display label
 */
function admin_csv_out(string $filename, array $rows, array $headers): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 correctly — Indian rupee and shade names break without it.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($headers));

    foreach ($rows as $r) {
        $line = [];
        foreach (array_keys($headers) as $k) {
            $line[] = $r[$k] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/** Shared "N of M" pager state for admin lists. */
function admin_paginate(int $total, int $perPage = 25): array
{
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = max(1, min(get_int('page', 1), $pages));
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage,
            'offset' => ($page - 1) * $perPage, 'total' => $total];
}
