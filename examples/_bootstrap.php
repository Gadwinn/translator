<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the example scripts.
 *
 * Loads the composer autoloader (with dev classes, so the test
 * stubs used by examples 08 and 09 are available) and provides
 * a tiny `assert_same()` helper that exits the script non-zero on
 * mismatch — that way the `tools/run-examples.php` runner picks
 * up the failure and surfaces it through `composer test-examples`.
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * Asserts that the actual value matches the expected value, exiting
 * the script with code 1 if not. Used so example failures fail CI.
 */
function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        fwrite(STDOUT, sprintf("  ok | %s\n", $label));
        return;
    }

    fwrite(STDERR, sprintf(
        "FAIL | %s\n  expected: %s\n  actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true),
    ));
    exit(1);
}
