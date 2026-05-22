<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 8 — translate an entire bag with an AI provider.
 *
 * To keep the example offline-runnable in CI, we plug in the
 * `StubAiProvider` from `tests/Stub/` instead of a real
 * `ClaudeProvider`. The orchestrator surface
 * (`BagTranslator::translateBag()`) is identical regardless of the
 * provider; production code would simply swap the provider in.
 *
 * The README equivalent of this script uses:
 *
 *     $http     = new CurlHttpClient();
 *     $provider = new ClaudeProvider($http, apiKey: getenv('ANTHROPIC_API_KEY'));
 *     $orchestrator = new BagTranslator($provider);
 */

use G\Translator\Bridge\Ai\BagTranslator;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Enum\Format;
use G\Translator\Enum\PluralCategory;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Io\Source\FileSource;
use G\Translator\Tests\Stub\StubAiProvider;

$detector = new FormatDetector();

// BagTranslator forwards each entry's `content()` (or `id()` when
// content is empty) as the source text. Our en.po stores the
// English text in msgstr, so the stub keys match those values.
$provider = new StubAiProvider();
$provider->setResponse('EN fallback value', new TranslationResult(translatedText: 'Repli'));
$provider->setResponse('Hello %name%',      new TranslationResult(translatedText: 'Witaj %name%'));
$provider->setResponse('One apple', new TranslationResult(
    translatedText: 'Jedno jabłko',
    translatedPluralForms: [
        PluralCategory::ONE->value   => 'Jedno jabłko',
        PluralCategory::FEW->value   => '{count} jabłka',
        PluralCategory::OTHER->value => '{count} jabłek',
    ],
));

$orchestrator = new BagTranslator($provider);

$sourceBag = $detector
    ->parserFor(new FileSource(__DIR__ . '/data/en.po'))
    ->parse(new FileSource(__DIR__ . '/data/en.po'), 'en');

$targetBag = $orchestrator->translateBag(
    source:       $sourceBag,
    targetLocale: 'pl-PL',
    quality:      TranslationQuality::HIGH,
    metadata:     ['formality' => 'tu', 'preserve_html' => true],
);

assert_same('pl-PL', $targetBag->locale(), 'target bag carries the requested locale');
assert_same('Repli', $targetBag->get('fallback.only')->content(), 'singular entry translated');
assert_same(
    '{count} jabłek',
    $targetBag->get('One apple')->pluralForms()[PluralCategory::OTHER->value] ?? null,
    'plural OTHER form propagated through orchestrator',
);

// Provider received quality + metadata on every issued request.
foreach ($provider->received as $request) {
    assert_same(TranslationQuality::HIGH, $request->quality, 'quality propagated');
    assert_same(['formality' => 'tu', 'preserve_html' => true], $request->metadata, 'metadata propagated');
}

// Serialize the result so the example mirrors the README's "po"
// round-trip too — proves the post-AI bag is a normal bag the
// existing serializers accept.
$po = $detector->serializerFor(Format::PO)->serialize($targetBag);
assert_same(true, str_contains($po, 'Witaj %name%'), 'PO serializer accepts the AI-translated bag');
