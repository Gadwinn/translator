<?php

declare(strict_types=1);

/**
 * Side-effect bootstrap for the example scripts.
 *
 * This file contains side effects ONLY:
 *   1. Loads the composer autoloader (with dev classes, so the
 *      test stubs used by examples 08 and 09 are available).
 *   2. Sends a `text/plain` Content-Type header when the script
 *      is reached through a non-CLI SAPI so browsers render the
 *      transcript verbatim.
 *   3. Pulls in `examples/_helpers.php`, which declares the
 *      assertion / output helpers.
 *
 * Splitting symbol declarations into `_helpers.php` keeps this
 * file compliant with PSR1's "either symbols or side effects,
 * never both" rule. The two files are paired: every example
 * requires `_bootstrap.php`, which in turn pulls the helpers.
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=UTF-8');
}

require __DIR__ . '/_helpers.php';
