<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 5 — strict mode for CI / development.
 *
 * Missing translations stay silent by default; strict mode turns
 * them into loud `MissingTranslationException` errors.
 */

use G\Translator\Exception\Runtime\MissingTranslationException;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector();
$source = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag = $detector->parserFor($source)->parse($source, locale: 'fr-FR');

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

// Lenient mode (default): missing key returns the id unchanged.
$lenient = $translator->trans('non.existing.key');
assert_same('non.existing.key', $lenient, 'lenient mode returns the id verbatim');

$translator->setStrict(true);

try {
    $translator->trans('non.existing.key');
    fwrite(STDERR, "FAIL: strict mode did not throw\n");
    exit(1);
} catch (MissingTranslationException $e) {
    // Loud failure: detect missing translations during testing.
    assert_same(
        true,
        str_contains($e->getMessage(), 'non.existing.key'),
        'strict-mode exception names the missing id',
    );
}
