<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 3 — context (`msgctxt`) and domains.
 *
 * Context entries live in the same PO file. The admin-domain entry
 * lives in a separate file; we parse it into the same bag with the
 * `admin` domain so a single `Translator` resolves both.
 */

use G\Translator\Enum\MergeStrategy;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Runtime\BagRegistry;
use G\Translator\Runtime\Translator;

$detector = new FormatDetector();

$messagesSource = new FileSource(__DIR__ . '/data/fr-FR.po');
$bag = $detector->parserFor($messagesSource)->parse($messagesSource, locale: 'fr-FR');

$adminSource = new FileSource(__DIR__ . '/data/fr-FR.admin.po');
$adminBag = $detector->parserFor($adminSource)->parse($adminSource, locale: 'fr-FR', domain: 'admin');
$bag->merge($adminBag, MergeStrategy::THROW_ON_CONFLICT);

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

// Disambiguation: "Post" as a noun vs. "Post" as a verb.
$noun = $translator->trans('Post', context: 'noun');
$verb = $translator->trans('Post', context: 'verb');
assert_same('Article', $noun, 'context "noun" picks the noun entry');
assert_same('Publier', $verb, 'context "verb" picks the verb entry');

// Domains separate logical scopes (admin, validators, messages, ...).
$welcome = $translator->trans('Welcome', domain: 'admin');
assert_same('Bienvenue admin', $welcome, 'domain "admin" picks the admin entry');
