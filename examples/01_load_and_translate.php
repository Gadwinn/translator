<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 1 — load a translation file and translate.
 *
 * Mirrors the canonical "five-line setup" the README promises.
 */

use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector(); // ships with all built-in parsers/serializers

$source = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag    = $detector->parserFor($source)->parse($source, locale: 'fr-FR');

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

$result = $translator->trans('Hello %name%', params: ['name' => 'Alice']);
// → "Bonjour Alice"
assert_same('Bonjour Alice', $result, 'simple placeholder substitution');
