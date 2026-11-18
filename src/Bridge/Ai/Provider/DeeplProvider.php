<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Provider;

use SensitiveParameter;
use G\Translator\Bridge\Ai\AiProviderInterface;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;
use G\Translator\Http\Enum\HttpMethod;
use G\Translator\Http\HttpClientInterface;
use G\Translator\Http\HttpRequest;
use G\Translator\Http\HttpResponse;
use JsonException;

/**
 * `AiProviderInterface` implementation backed by the DeepL REST API.
 *
 * DeepL is a text-only translation API: it produces a single target
 * string per request. **Plural support is NOT possible** — the
 * `sourcePluralText` field is ignored and
 * `TranslationResult::$translatedPluralForms` is always empty. The
 * caller is responsible for issuing additional requests for each
 * plural form they need to produce.
 *
 * Endpoint selection:
 *
 *   - DeepL **Free** API keys end with `:fx`. By default the
 *     provider routes those keys to `https://api-free.deepl.com`.
 *   - Other keys use `https://api.deepl.com` (Pro).
 *   - The endpoint can be overridden explicitly via the constructor
 *     for testing or self-hosted proxies.
 *
 * ## Locale handling
 *
 * DeepL uses uppercase ISO 639-1 codes plus optional region (e.g.,
 * `EN-GB`, `PT-BR`). The provider converts BCP-47 → DeepL format
 * by upper-casing the input. Locales not understood by DeepL
 * produce an `AiProviderException` carrying the upstream error
 * message verbatim.
 *
 * ## Batch failure policy
 *
 * `translateBatch()` is **fail-fast**: any non-success response
 * propagates immediately. (DeepL also supports passing multiple
 * `text[]` strings per request — this provider does NOT batch
 * server-side, it issues one HTTP request per `TranslationRequest`
 * for simplicity and to keep error attribution clean.)
 */
final class DeeplProvider implements AiProviderInterface
{
    /**
     * @var non-empty-string Stable provider identifier.
     */
    public const string PROVIDER_NAME = 'deepl';

    /**
     * @var non-empty-string Default DeepL Free endpoint.
     */
    public const string FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';

    /**
     * @var non-empty-string Default DeepL Pro endpoint.
     */
    public const string PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    /**
     * @var non-empty-string Suffix marking a DeepL Free key.
     */
    private const string FREE_KEY_SUFFIX = ':fx';

    private const int HTTP_UNAUTHORIZED = 401;
    private const int HTTP_FORBIDDEN = 403;
    private const int HTTP_TOO_MANY_REQUESTS = 429;

    /**
     * Maximum length of an error-body excerpt embedded in thrown
     * `AiProviderException` messages — keeps stack traces readable
     * when the API returns a large HTML or JSON payload on failure.
     */
    private const int ERROR_SNIPPET_MAX_LENGTH = 240;

    private readonly string $effectiveEndpoint;

    /**
     * @param HttpClientInterface $http
     * @param non-empty-string $apiKey The DeepL Authentication Key.
     * @param non-empty-string|null $endpoint Explicit override; when
     *        omitted, the endpoint is derived from the key suffix.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[SensitiveParameter]
        private readonly string $apiKey,
        ?string $endpoint = null,
    ) {
        $this->effectiveEndpoint = $endpoint ?? (
            str_ends_with($apiKey, self::FREE_KEY_SUFFIX)
                ? self::FREE_ENDPOINT
                : self::PRO_ENDPOINT
        );
    }

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $response = $this->http->send($this->buildHttpRequest($request));

        return $this->parseResponse($response);
    }

    /**
     * @param array<array-key, TranslationRequest> $requests
     *
     * @return array<array-key, TranslationResult>
     */
    public function translateBatch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $httpRequests = [];
        foreach ($requests as $key => $request) {
            $httpRequests[$key] = $this->buildHttpRequest($request);
        }

        $responses = $this->http->sendMany($httpRequests);

        /** @var array<array-key, TranslationResult> $results */
        $results = [];
        foreach ($responses as $key => $response) {
            $results[$key] = $this->parseResponse($response);
        }

        return $results;
    }

    private function buildHttpRequest(TranslationRequest $request): HttpRequest
    {
        $payload = [
            'text'        => [$request->sourceText],
            'target_lang' => $this->toDeeplLocale($request->targetLocale),
            'source_lang' => $this->toDeeplLocale($request->sourceLocale),
        ];

        return new HttpRequest(
            method: HttpMethod::POST,
            url: $this->effectiveEndpoint,
            headers: [
                'authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                'content-type'  => 'application/json',
            ],
            body: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    private function toDeeplLocale(string $locale): string
    {
        // DeepL accepts both `EN` and `EN-GB`; canonicalize by
        // upper-casing the whole tag and replacing `_` with `-`.
        return strtoupper(str_replace('_', '-', $locale));
    }

    private function parseResponse(HttpResponse $response): TranslationResult
    {
        $this->assertSuccessful($response);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AiProviderException(
                'DeepL returned a body that is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded) || !isset($decoded['translations']) || !is_array($decoded['translations'])) {
            throw new AiProviderException('DeepL response is missing the "translations" array.');
        }

        $first = $decoded['translations'][0] ?? null;
        if (!is_array($first) || !isset($first['text']) || !is_string($first['text'])) {
            throw new AiProviderException('DeepL response has no "text" field on the first translation.');
        }

        $metadata = [];
        if (isset($first['detected_source_language']) && is_string($first['detected_source_language'])) {
            $metadata['detected_source_language'] = $first['detected_source_language'];
        }

        return new TranslationResult(
            translatedText: $first['text'],
            translatedPluralForms: [],
            metadata: $metadata,
        );
    }

    private function assertSuccessful(HttpResponse $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }
        if (in_array($response->statusCode, [self::HTTP_UNAUTHORIZED, self::HTTP_FORBIDDEN], true)) {
            throw new AiAuthenticationException(sprintf(
                'DeepL rejected the credentials (HTTP %d). Body: %s',
                $response->statusCode,
                $this->snippet($response->body),
            ));
        }
        if ($response->statusCode === self::HTTP_TOO_MANY_REQUESTS) {
            throw new AiRateLimitException(sprintf(
                'DeepL rate limit hit (HTTP 429). Body: %s',
                $this->snippet($response->body),
            ));
        }

        throw new AiProviderException(sprintf(
            'DeepL returned HTTP %d. Body: %s',
            $response->statusCode,
            $this->snippet($response->body),
        ));
    }

    private function snippet(string $body): string
    {
        if (strlen($body) <= self::ERROR_SNIPPET_MAX_LENGTH) {
            return $body;
        }

        return substr($body, 0, self::ERROR_SNIPPET_MAX_LENGTH) . '...';
    }
}
