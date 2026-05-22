<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 2 — plural translation with automatic `{count}`
 * injection.
 */

use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector();
$source = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag    = $detector->parserFor($source)->parse($source, locale: 'fr-FR');

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

$five = $translator->plural('One apple', '{count} apples', 5);
// → "5 pommes"
assert_same('5 pommes', $five, 'plural() auto-injects {count}');

// Override the injected count (e.g. formatted number).
$thousand = $translator->plural(
    id:     'One apple',
    plural: '{count} apples',
    count:  5000,
    params: ['count' => number_format(5000, 0, ',', ' ')],
);
// → "5 000 pommes"
assert_same('5 000 pommes', $thousand, 'caller-provided count overrides auto-injection');

// Singular form for count=1.
$singular = $translator->plural('One apple', '{count} apples', 1);
assert_same('Une pomme', $singular, 'count=1 selects the ONE form');
