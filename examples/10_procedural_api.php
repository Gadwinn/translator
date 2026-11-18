<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// The procedural API is opt-in: consumers enable it via the
// `autoload.files` entry of their `composer.json`. From inside the
// repo we load the file directly.
require __DIR__ . '/../src/Functions/translate.php';

/**
 * README section 10 — procedural API (Gettext-style).
 *
 * Bootstrap a default translator, then call the eight global
 * functions. Each call returns a translated string; we verify the
 * happy-path outputs to keep CI honest if any signature drifts.
 */

use G\Translator\Functions\TranslatorRegistry;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector();

$messagesSource = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag = $detector->parserFor($messagesSource)->parse($messagesSource, locale: 'fr-FR');

$adminSource = new FileSource(__DIR__ . '/data/fr-FR.admin.po');
$bag->merge($detector->parserFor($adminSource)->parse($adminSource, locale: 'fr-FR', domain: 'admin'));

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

// Bootstrap once.
TranslatorRegistry::setDefault($translator);

// Anywhere in the app.
assert_same('Bonjour Alice',     __('Hello %name%',                   ['name' => 'Alice']), '__()');
assert_same('Bienvenue admin',   __d('admin', 'Welcome'),                                   '__d()');
assert_same('5 pommes',          __n('One apple', '{count} apples', 5),                     '__n()');
assert_same('Publier',           __p('verb',   'Post'),                                     '__p()');
assert_same('Article',           __dp('messages', 'noun', 'Post'),                          '__dp()');
assert_same('Une pomme',         __n('One apple', '{count} apples', 1),                     '__n() singular');

// Named scopes for applications running multiple translators side by side.
$adminTranslator = new Translator($registry);
$adminTranslator->setLocale('fr-FR');
TranslatorRegistry::register('admin', $adminTranslator);

TranslatorRegistry::use('admin');
assert_same('Bienvenue admin', __d('admin', 'Welcome'), 'scope routes to the named translator');

TranslatorRegistry::use(null);
assert_same('Bonjour Alice', __('Hello %name%', ['name' => 'Alice']), 'null scope reverts to default');

// Clean up to keep test isolation if this file is required twice.
TranslatorRegistry::reset();
