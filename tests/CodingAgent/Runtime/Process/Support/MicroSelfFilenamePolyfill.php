<?php

declare(strict_types=1);

/**
 * Test-only polyfill for PHP-micro's self path helper.
 *
 * Production fused natives define this natively. Source/PHAR PHPUnit hosts do
 * not, so the fused-native locator contract needs a controllable stub.
 */
if (!function_exists('micro_get_self_filename')) {
    function micro_get_self_filename(): string
    {
        return (string) ($GLOBALS['hatfield_test_micro_self'] ?? '');
    }
}
