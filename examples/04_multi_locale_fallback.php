<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 4 — multi-locale with automatic fallback.
 *
 * fr-FR has no entry for `fallback.only`; the en bag does. With the
 * explicit chain set to `[en-GB, en]` and auto-parent enabled, the
 * resolution order becomes `pl-PL → pl → en-GB → en`, and the en
 * bag's entry wins.
 */

use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\LocaleFallback;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector();

$frSource = new FileSource(__DIR__ . '/data/fr-FR.po');
$frBag    = $detector->parserFor($frSource)->parse($frSource, locale: 'fr-FR');

$enSource = new FileSource(__DIR__ . '/data/en.po');
$enBag    = $detector->parserFor($enSource)->parse($enSource, locale: 'en');

$registry = new BagRegistry();
$registry->register('fr-FR', $frBag);
$registry->register('en', $enBag);

$fallback = new LocaleFallback();
$fallback->setPrimary('pl-PL');
$fallback->setChain(['en-GB', 'en']); // explicit fallback list
$fallback->setAutoParent(true);        // also try pl, en-GB → en, etc.

// Resolution chain becomes: pl-PL → pl → en-GB → en
$translator = new Translator($registry, $fallback);

$resolved = $translator->trans('fallback.only');
assert_same('EN fallback value', $resolved, 'chain falls through pl-PL → pl → en-GB → en');
