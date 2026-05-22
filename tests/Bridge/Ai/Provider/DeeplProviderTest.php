<?php

declare(strict_types=1);

namespace G\Translator\Tests\Bridge\Ai\Provider;

use G\Translator\Bridge\Ai\Provider\DeeplProvider;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;
use G\Translator\Http\HttpResponse;
use G\Translator\Tests\Stub\StubHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeeplProvider::class)]
final class DeeplProviderTest extends TestCase
{
    public function testProviderName(): void
    {
        self::assertSame('deepl', (new DeeplProvider(new StubHttpClient(), 'k'))->name());
    }

    public function testTranslateReturnsTextOnly(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [
                ['detected_source_language' => 'EN', 'text' => 'Bonjour'],
            ],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'deepl-key:fx');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr',
        ));

        self::assertSame('Bonjour', $result->translatedText);
        self::assertSame([], $result->translatedPluralForms, 'DeepL never returns plural forms');
        self::assertSame('EN', $result->metadata['detected_source_language']);
    }

    public function testPluralSourceIsIgnoredForDeepL(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'Une pomme']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'k');

        $result = $provider->translate(new TranslationRequest(
            sourceText: 'One apple',
            sourceLocale: 'en',
            targetLocale: 'fr',
            sourcePluralText: '%d apples', // present, but DeepL ignores it
        ));

        self::assertSame('Une pomme', $result->translatedText);
        self::assertSame([], $result->translatedPluralForms);
    }

    public function testFreeKeyRoutesToFreeEndpoint(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'X']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'aaa-bbb:fx');

        $provider->translate(self::sampleRequest());

        self::assertSame('https://api-free.deepl.com/v2/translate', $http->sent[0]->url);
    }

    public function testProKeyRoutesToProEndpoint(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'X']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'aaa-bbb');

        $provider->translate(self::sampleRequest());

        self::assertSame('https://api.deepl.com/v2/translate', $http->sent[0]->url);
    }

    public function testAuthorizationHeaderFormat(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'X']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'my-secret-key:fx');

        $provider->translate(self::sampleRequest());

        self::assertSame('DeepL-Auth-Key my-secret-key:fx', $http->sent[0]->headers['authorization']);
    }

    public function testLocaleConversionToDeepLConvention(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'X']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'k');

        $provider->translate(new TranslationRequest(
            sourceText: 'Hi',
            sourceLocale: 'en-us',
            targetLocale: 'pt_BR',
        ));

        $body = $http->sent[0]->body ?? '';
        self::assertStringContainsString('"target_lang":"PT-BR"', $body);
        self::assertStringContainsString('"source_lang":"EN-US"', $body);
    }

    public function testAuthErrorMapping(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(403, [], 'forbidden'));
        $provider = new DeeplProvider($http, 'k');

        $this->expectException(AiAuthenticationException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testRateLimitMapping(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(429, [], 'too many'));
        $provider = new DeeplProvider($http, 'k');

        $this->expectException(AiRateLimitException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testProviderErrorMapping(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(500, [], 'oops'));
        $provider = new DeeplProvider($http, 'k');

        $this->expectException(AiProviderException::class);
        $provider->translate(self::sampleRequest());
    }

    public function testTranslateBatchPreservesKeys(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'A']],
        ], JSON_THROW_ON_ERROR)));
        $http->enqueueResponse(self::successResponse(json_encode([
            'translations' => [['text' => 'B']],
        ], JSON_THROW_ON_ERROR)));
        $provider = new DeeplProvider($http, 'k');

        $results = $provider->translateBatch([
            'a' => self::sampleRequest(),
            'b' => self::sampleRequest(),
        ]);

        self::assertSame(['a', 'b'], array_keys($results));
        self::assertSame('A', $results['a']->translatedText);
        self::assertSame('B', $results['b']->translatedText);
    }

    private static function sampleRequest(): TranslationRequest
    {
        return new TranslationRequest(
            sourceText: 'Hello',
            sourceLocale: 'en',
            targetLocale: 'fr',
        );
    }

    private static function successResponse(string $body): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], $body);
    }
}
