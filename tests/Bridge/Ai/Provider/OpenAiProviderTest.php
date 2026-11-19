<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Provider;

use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Provider\OpenAiProvider;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;
use G\Translator\Http\HttpResponse;
use G\Translator\Tests\Stub\StubHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiProvider::class)]
final class OpenAiProviderTest extends TestCase
{
    public function testProviderName(): void
    {
        self::assertSame('openai', (new OpenAiProvider(new StubHttpClient(), 'k'))->name());
    }

    public function testTranslateSingularReturnsTranslatedText(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'Bonjour'])));
        $provider = new OpenAiProvider($http, 'sk-test');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
        ));

        self::assertSame('Bonjour', $result->translatedText);
    }

    public function testTranslateWithPluralReturnsAllForms(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody([
            'translatedText' => 'Une pomme',
            'plurals' => [
                'one'   => 'Une pomme',
                'other' => '%count% pommes',
            ],
        ])));
        $provider = new OpenAiProvider($http, 'sk-test');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
            sourcePluralText: '%count% apples',
        ));

        self::assertSame('%count% pommes', $result->translatedPluralForms[PluralCategory::OTHER->value]);
    }

    public function testRequestCarriesBearerAndJsonModeFlag(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'ok'])));
        $provider = new OpenAiProvider($http, 'sk-secret');

        $provider->translate(self::sampleRequest());

        self::assertCount(1, $http->sent);
        $sent = $http->sent[0];
        self::assertSame('https://api.openai.com/v1/chat/completions', $sent->url);
        self::assertSame('Bearer sk-secret', $sent->headers['authorization']);
        self::assertNotNull($sent->body);
        self::assertStringContainsString('"response_format":{"type":"json_object"}', $sent->body);
        self::assertStringContainsString('"model":"gpt-4o"', $sent->body); // STANDARD default
    }

    public function testFastQualitySelectsMiniModel(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'x'])));
        $provider = new OpenAiProvider($http, 'sk');

        $provider->translate(new TranslationRequest(
            sourceText: 'Hi',
            sourceLocale: 'en',
            targetLocale: 'fr',
            quality: TranslationQuality::FAST,
        ));

        self::assertStringContainsString('"model":"gpt-4o-mini"', $http->sent[0]->body ?? '');
    }

    public function testAuthErrorMapsTo401(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(401, [], '{"error":"invalid"}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiAuthenticationException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testRateLimitMapsTo429(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(429, [], '{"error":"rate"}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiRateLimitException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testServerErrorMapsToProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(503, [], 'unavailable'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testMalformedMessageContentRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['content' => 'not json']]],
            'model' => 'x',
        ]) ?: '{}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testEmptyBatchReturnsEmptyArray(): void
    {
        $http = new StubHttpClient();
        $provider = new OpenAiProvider($http, 'sk');

        self::assertSame([], $provider->translateBatch([]));
    }

    public function testInvalidJsonBodyRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], '{not json'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('OpenAI returned a body that is not valid JSON');
        $provider->translate(self::sampleRequest());
    }

    public function testEmptyMessageContentYieldsEmptyTranslation(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['content' => '   ']]],
        ]) ?: '{}'));
        $provider = new OpenAiProvider($http, 'sk');

        $result = $provider->translate(self::sampleRequest());

        self::assertSame('', $result->translatedText);
    }

    public function testInvalidPluralCategoryEntriesAreSilentlyDropped(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody([
            'translatedText' => 'Une pomme',
            'plurals' => [
                'one'      => 'Une pomme',
                'other'    => '%count% pommes',
                'bogus'    => 'invalid category, dropped',
                42         => 'non-string key, dropped',
                'few'      => 99, // non-string value, dropped
            ],
        ])));
        $provider = new OpenAiProvider($http, 'sk');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr',
            sourcePluralText: '%count% apples',
        ));

        self::assertSame(
            ['one', 'other'],
            array_keys($result->translatedPluralForms),
        );
    }

    public function testRequestIncludesContextWhenProvided(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'x'])));
        $provider = new OpenAiProvider($http, 'sk');

        $provider->translate(new TranslationRequest(
            sourceText: 'Hi',
            sourceLocale: 'en',
            targetLocale: 'fr',
            context: 'a friendly greeting',
        ));

        self::assertStringContainsString('friendly greeting', $http->sent[0]->body ?? '');
    }

    public function testMissingChoicesArrayRaisesException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], '{"unrelated":"shape"}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('missing the "choices" array');
        $provider->translate(self::sampleRequest());
    }

    public function testChoiceWithoutMessageRaisesException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['finish_reason' => 'stop']],
        ]) ?: '{}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('choice has no "message"');
        $provider->translate(self::sampleRequest());
    }

    public function testMessageContentNonStringRaisesException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['content' => 42]]],
        ]) ?: '{}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('no string "content"');
        $provider->translate(self::sampleRequest());
    }

    public function testStructuredTextScalarRaisesException(): void
    {
        $http = new StubHttpClient();
        // Valid JSON inside content, but a scalar (number), not an object.
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'choices' => [['message' => ['content' => '42']]],
        ]) ?: '{}'));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('non-object value');
        $provider->translate(self::sampleRequest());
    }

    public function testLongErrorBodyIsTruncatedInExceptionMessage(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(500, [], str_repeat('B', 500)));
        $provider = new OpenAiProvider($http, 'sk');

        try {
            $provider->translate(self::sampleRequest());
            self::fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            self::assertStringContainsString('...', $e->getMessage());
        }
    }

    public function testPluralFormsWithoutOtherCategoryRaisesException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody([
            'translatedText' => 'X',
            'plurals' => ['one' => 'Une pomme'], // no "other" key
        ])));
        $provider = new OpenAiProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('mandatory "other" category');
        $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr',
            sourcePluralText: '%count% apples',
        ));
    }

    public function testTranslateBatchPreservesKeys(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'A'])));
        $http->enqueueResponse(self::successResponse(self::openAiBody(['translatedText' => 'B'])));
        $provider = new OpenAiProvider($http, 'sk');

        $results = $provider->translateBatch([
            42 => self::sampleRequest(),
            'k' => self::sampleRequest(),
        ]);

        self::assertSame([42, 'k'], array_keys($results));
    }

    public function testTruncatedAnswerRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse((string) json_encode([
            'choices' => [[
                'finish_reason' => 'length',
                'message' => ['role' => 'assistant', 'content' => '{"translatedText": "Bon'],
            ]],
        ])));

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('ran out of tokens');
        (new OpenAiProvider($http, 'k'))->translate(self::sampleRequest());
    }

    private static function sampleRequest(): TranslationRequest
    {
        return new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
        );
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function openAiBody(array $envelope): string
    {
        $payload = [
            'model' => 'gpt-test',
            'usage' => ['total_tokens' => 12],
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($envelope, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function successResponse(string $body): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], $body);
    }
}
