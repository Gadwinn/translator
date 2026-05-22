<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/**
 * README section 9 — per-request AI translation.
 *
 * This example wires the **real** `ClaudeProvider` against a
 * `StubHttpClient` returning a canned Anthropic Messages response.
 * That exercises the full request-building / response-parsing
 * surface of the provider without any outbound HTTP call —
 * exactly the regression net we want from `composer
 * test-examples`.
 *
 * Production code would replace `StubHttpClient` with
 * `CurlHttpClient` and the API key would come from
 * `getenv('ANTHROPIC_API_KEY')` (the README's literal example).
 */

use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Provider\ClaudeProvider;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Http\HttpResponse;
use G\Translator\Tests\Stub\StubHttpClient;

$http = new StubHttpClient();

// Canned response shape mirrors Anthropic's Messages API:
//   { content: [ { type: "text", text: "<json envelope>" } ], model, usage }
$envelope = json_encode(['translatedText' => 'Bienvenue sur le tableau de bord'], JSON_THROW_ON_ERROR);
$apiBody  = json_encode([
    'model'   => 'claude-sonnet-4-6',
    'usage'   => ['input_tokens' => 28, 'output_tokens' => 9],
    'content' => [['type' => 'text', 'text' => $envelope]],
], JSON_THROW_ON_ERROR);

$http->enqueueResponse(new HttpResponse(200, ['content-type' => 'application/json'], $apiBody));

$provider = new ClaudeProvider($http, apiKey: 'sk-example-not-a-real-key');

$result = $provider->translate(new TranslationRequest(
    sourceText:        'Welcome to the dashboard',
    sourceLocale:      'en',
    targetLocale:      'fr-FR',
    context:           'dashboard heading',
    quality:           TranslationQuality::STANDARD,
));

assert_same('Bienvenue sur le tableau de bord', $result->translatedText, 'singular translation parsed');
assert_same([], $result->translatedPluralForms,                          'no plural forms when none requested');
assert_same('claude-sonnet-4-6', $result->metadata['model'] ?? null,     'model echoed back through metadata');

// Sanity-check that the provider built a well-formed request.
assert_same('https://api.anthropic.com/v1/messages', $http->sent[0]->url, 'provider hit the Messages endpoint');
assert_same('sk-example-not-a-real-key', $http->sent[0]->headers['x-api-key'] ?? null, 'API key forwarded');
