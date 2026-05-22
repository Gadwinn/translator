<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 6 — stacking multiple sources.
 *
 * Three layers: base < tenant < user. With `REPLACE` the highest
 * priority wins. With `THROW_ON_CONFLICT` (the default), any
 * collision would raise — that's the point: silent overrides
 * require an explicit opt-in.
 */

use G\Translator\Engine\StackEngine;
use G\Translator\Engine\StackedSource;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\StringSource;

$detector = new FormatDetector();
$engine = new StackEngine($detector);

$engine->add(new StackedSource(
    new StringSource('{"greeting":"Hello","brand":"Default"}', extension: 'json'),
    priority: 0,
));
$engine->add(new StackedSource(
    new StringSource('{"brand":"Acme"}', extension: 'json'),
    priority: 10,
));
$engine->add(new StackedSource(
    new StringSource('{"greeting":"Bonjour, Willy!"}', extension: 'json'),
    priority: 20,
));

// Default = THROW_ON_CONFLICT: any collision raises MergeException.
// Pick REPLACE explicitly when overrides are intentional.
$mergedBag = $engine->resolve('fr-FR', MergeStrategy::REPLACE);

assert_same('Bonjour, Willy!', $mergedBag->get('greeting')->content(), 'priority 20 wins greeting');
assert_same('Acme',            $mergedBag->get('brand')->content(),    'priority 10 wins brand');
