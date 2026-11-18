<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 7 — serialize a bag to a different format.
 *
 * Parses a PO bag, serializes it as JSON, decodes the result and
 * sanity-checks the shape. The serializer writes a flat dictionary
 * (`Format::JSON`); the Angular JSON serializer would have produced
 * a nested object.
 */

use G\Translator\Enum\Format;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;

$detector = new FormatDetector();
$source = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag = $detector->parserFor($source)->parse($source, locale: 'fr-FR');

$json = $detector->serializerFor(Format::JSON)->serialize($bag);

$decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

assert_same(true, is_array($decoded),                  'JSON serializer produces a top-level object');
assert_same('Bonjour %name%', $decoded['Hello %name%'] ?? null, 'singular entry serialized');
// JSON has no context dimension: the two "Post" entries (noun, verb)
// collapse to the same key. PHP's last-assignment-wins semantics apply —
// iteration order matches insertion order, so "verb" (parsed second) wins.
assert_same('Publier', $decoded['Post'] ?? null, 'last "Post" entry wins in flat JSON output');
