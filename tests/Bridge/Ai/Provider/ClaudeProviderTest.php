<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Provider;

use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Provider\ClaudeProvider;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;
use G\Translator\Http\HttpResponse;
use G\Translator\Tests\Stub\StubHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaudeProvider::class)]
final class ClaudeProviderTest extends TestCase
{
    public function testProviderName(): void
    {
        self::assertSame('claude', (new ClaudeProvider(new StubHttpClient(), 'k'))->name());
    }

    public function testTranslateSingularReturnsTranslatedText(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(
            self::claudeBody(['translatedText' => 'Bonjour'])
        ));
        $provider = new ClaudeProvider($http, 'sk-test');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
        ));

        self::assertSame('Bonjour', $result->translatedText);
        self::assertSame([], $result->translatedPluralForms);
    }

    public function testTranslateWithPluralReturnsAllForms(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::claudeBody([
            'translatedText' => 'Une pomme',
            'plurals' => [
                'one'   => 'Une pomme',
                'other' => '%count% pommes',
            ],
        ])));
        $provider = new ClaudeProvider($http, 'sk-test');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
            sourcePluralText: '%count% apples',
        ));

        self::assertSame('Une pomme', $result->translatedText);
        self::assertSame('Une pomme', $result->translatedPluralForms[PluralCategory::ONE->value]);
        self::assertSame('%count% pommes', $result->translatedPluralForms[PluralCategory::OTHER->value]);
    }

    public function testRequestCarriesAuthHeadersAndCorrectPayload(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::claudeBody(['translatedText' => 'ok'])));
        $provider = new ClaudeProvider($http, 'sk-secret');

        $provider->translate(new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
            context: 'a friendly greeting',
            quality: TranslationQuality::HIGH,
        ));

        self::assertCount(1, $http->sent);
        $sent = $http->sent[0];
        self::assertSame('https://api.anthropic.com/v1/messages', $sent->url);
        self::assertSame('sk-secret', $sent->headers['x-api-key']);
        self::assertSame('2023-06-01', $sent->headers['anthropic-version']);
        self::assertNotNull($sent->body);
        self::assertStringContainsString('claude-opus-4-7', $sent->body);
        self::assertStringContainsString('Hello', $sent->body);
        self::assertStringContainsString('friendly greeting', $sent->body);
    }

    public function testModelSelectionByQuality(): void
    {
        $cases = [
            [TranslationQuality::FAST,     'claude-haiku-4-5-20251001'],
            [TranslationQuality::STANDARD, 'claude-sonnet-4-6'],
            [TranslationQuality::HIGH,     'claude-opus-4-7'],
        ];

        foreach ($cases as [$quality, $expectedModel]) {
            $http = new StubHttpClient();
            $http->enqueueResponse(self::successResponse(self::claudeBody(['translatedText' => 'x'])));
            $provider = new ClaudeProvider($http, 'sk');
            $provider->translate(new TranslationRequest(
                sourceText: 'Hi',
                sourceLocale: 'en',
                targetLocale: 'fr',
                quality: $quality,
            ));
            self::assertStringContainsString(
                $expectedModel,
                $http->sent[0]->body ?? '',
                'Quality ' . $quality->value . ' must select model ' . $expectedModel,
            );
        }
    }

    public function testAuthErrorMapsTo401Or403(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(401, [], '{"error":"unauthorized"}'));
        $provider = new ClaudeProvider($http, 'bad-key');

        $this->expectException(AiAuthenticationException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testRateLimitMapsTo429(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(429, [], '{"error":"too many"}'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiRateLimitException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testServerErrorMapsToProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(500, [], 'oops'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testMalformedJsonBodyRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], 'not json at all'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testMissingContentBlockRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], '{"model":"x"}'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testPluralWithoutOtherCategoryIsRejected(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::claudeBody([
            'translatedText' => 'Une pomme',
            'plurals' => ['one' => 'Une pomme'], // no "other"
        ])));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr-FR',
            sourcePluralText: '%count% apples',
        ));
    }

    public function testTranslateBatchPreservesKeys(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::claudeBody(['translatedText' => 'A'])));
        $http->enqueueResponse(self::successResponse(self::claudeBody(['translatedText' => 'B'])));
        $provider = new ClaudeProvider($http, 'sk');

        $results = $provider->translateBatch([
            'first'  => self::sampleRequest(),
            'second' => self::sampleRequest(),
        ]);

        self::assertSame(['first', 'second'], array_keys($results));
        self::assertSame('A', $results['first']->translatedText);
        self::assertSame('B', $results['second']->translatedText);
    }

    public function testEmptyBatchReturnsEmptyArray(): void
    {
        $provider = new ClaudeProvider(new StubHttpClient(), 'sk');
        self::assertSame([], $provider->translateBatch([]));
    }

    public function testNonArrayContentBlocksAreSkipped(): void
    {
        $http = new StubHttpClient();
        // First block is a bare string (not an array), second block is
        // the actual text. The non-array block must be silently skipped.
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'content' => [
                'not-an-array',
                ['type' => 'text', 'text' => json_encode(['translatedText' => 'Bonjour'])],
            ],
        ]) ?: '{}'));
        $provider = new ClaudeProvider($http, 'sk');

        $result = $provider->translate(self::sampleRequest());

        self::assertSame('Bonjour', $result->translatedText);
    }

    public function testEmptyTextBlockYieldsEmptyTranslation(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => '   ']],
        ]) ?: '{}'));
        $provider = new ClaudeProvider($http, 'sk');

        $result = $provider->translate(self::sampleRequest());

        self::assertSame('', $result->translatedText);
    }

    public function testTextBlockWithInvalidJsonRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => '{not json']],
        ]) ?: '{}'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('not the expected JSON envelope');
        $provider->translate(self::sampleRequest());
    }

    public function testTextBlockWithScalarJsonRaisesProviderException(): void
    {
        $http = new StubHttpClient();
        // Valid JSON, but a scalar (string), not an object.
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => '"just a string"']],
        ]) ?: '{}'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('non-object value');
        $provider->translate(self::sampleRequest());
    }

    public function testMissingTextContentBlockRaisesException(): void
    {
        $http = new StubHttpClient();
        // content array exists but no block of type "text".
        $http->enqueueResponse(new HttpResponse(200, [], json_encode([
            'content' => [['type' => 'image', 'source' => 'whatever']],
        ]) ?: '{}'));
        $provider = new ClaudeProvider($http, 'sk');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('did not contain a "text" content block');
        $provider->translate(self::sampleRequest());
    }

    public function testLongErrorBodyIsTruncatedInExceptionMessage(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(500, [], str_repeat('C', 500)));
        $provider = new ClaudeProvider($http, 'sk');

        try {
            $provider->translate(self::sampleRequest());
            self::fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            self::assertStringContainsString('...', $e->getMessage());
        }
    }

    public function testInvalidPluralEntriesAreSilentlyDropped(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(self::claudeBody([
            'translatedText' => 'Une pomme',
            'plurals' => [
                'one'   => 'Une pomme',
                'other' => '%count% pommes',
                'bogus' => 'invalid CLDR category, dropped',
                42      => 'non-string key, dropped',
                'few'   => 99, // non-string value, dropped
            ],
        ])));
        $provider = new ClaudeProvider($http, 'sk');

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
    private static function claudeBody(array $envelope): string
    {
        $payload = [
            'model'   => 'claude-test',
            'usage'   => ['input_tokens' => 5, 'output_tokens' => 8],
            'content' => [
                ['type' => 'text', 'text' => json_encode($envelope, JSON_UNESCAPED_UNICODE)],
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function successResponse(string $body): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], $body);
    }
}
