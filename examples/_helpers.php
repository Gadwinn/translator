<?php

declare(strict_types=1);

/**
 * SAPI-aware output helpers shared by every example script.
 *
 * Function declarations only — no side effects, so this file
 * complies with PSR1's "either symbols or side effects, never
 * both" rule. The matching side-effect bootstrap (autoload, web
 * Content-Type) lives in `examples/_bootstrap.php`, which is the
 * file every example requires directly.
 *
 *   - **CLI** (`php examples/01_*.php`) — `STDOUT` / `STDERR`
 *     routing exactly like before. `tools/run-examples.php` keeps
 *     observing exit codes as the source of truth.
 *
 *   - **Web** (open through a local PHP server) — identical lines
 *     are echoed straight into the response body. The bootstrap
 *     sets `Content-Type: text/plain` so newlines render verbatim;
 *     a failed assertion additionally sets HTTP 500 so browser
 *     devtools and reverse proxies flag the failure.
 *
 * `STDOUT` / `STDERR` are CLI-only constants; referencing them
 * unconditionally would emit "Undefined constant" warnings under
 * any non-CLI SAPI. All output therefore goes through the helpers
 * below, never directly through `fwrite()` from caller code.
 */

if (!function_exists('example_out')) {
    /**
     * Writes a normal-status line (CLI: STDOUT; web: response body).
     */
    function example_out(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $message);
            return;
        }
        echo $message;
    }
}

if (!function_exists('example_err')) {
    /**
     * Writes a failure line (CLI: STDERR; web: response body).
     *
     * Output channels differ but the visible content is identical
     * so the per-example transcript reads the same in both contexts.
     */
    function example_err(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message);
            return;
        }
        echo $message;
    }
}

if (!function_exists('assert_same')) {
    /**
     * Asserts that `$actual` matches `$expected`, ending the script
     * with code 1 (and HTTP 500 under web SAPIs) when it does not.
     */
    function assert_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            example_out(sprintf("  ok | %s\n", $label));
            return;
        }

        example_err(sprintf(
            "FAIL | %s\n  expected: %s\n  actual:   %s\n",
            $label,
            var_export($expected, true),
            var_export($actual, true),
        ));

        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
        }
        exit(1);
    }
}
