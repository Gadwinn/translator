<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Provider;

use SensitiveParameter;
use G\Translator\Bridge\Ai\AiProviderInterface;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;
use G\Translator\Http\Enum\HttpMethod;
use G\Translator\Http\HttpClientInterface;
use G\Translator\Http\HttpRequest;
use G\Translator\Http\HttpResponse;
use JsonException;

/**
 * `AiProviderInterface` implementation backed by Anthropic's
 * Messages API.
 *
 * Model selection follows `TranslationQuality`:
 *
 *   - `FAST`     → `claude-haiku-4-5-20251001`
 *   - `STANDARD` → `claude-sonnet-4-6`
 *   - `HIGH`     → `claude-opus-4-7`
 *
 * Plural support: when `TranslationRequest::$sourcePluralText` is
 * non-null, the provider asks Claude (via a strict JSON-only
 * system prompt) for every CLDR plural form of the target locale.
 * The response is parsed and stored in
 * `TranslationResult::$translatedPluralForms`, keyed by
 * `PluralCategory::value`. When the source has no plural form,
 * only `translatedText` is populated.
 *
 * ## Batch failure policy
 *
 * `translateBatch()` is **fail-fast**: the first non-success response
 * (or first malformed JSON) raises immediately. Successfully
 * translated requests prior to the failure are discarded — the
 * call is "all or nothing". Consumers needing partial-success
 * semantics SHOULD wrap individual `translate()` calls themselves.
 *
 * ## Credentials
 *
 * The API key is injected via the constructor (`$apiKey`). The
 * provider NEVER reads `getenv()` or any global state; secret
 * sourcing is the caller's concern.
 */
final class ClaudeProvider implements AiProviderInterface
{
    /**
     * @var non-empty-string Stable provider identifier.
     */
    public const string PROVIDER_NAME = 'claude';

    /**
     * @var non-empty-string Anthropic Messages API endpoint.
     */
    public const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * @var non-empty-string Anthropic API version pinned by the
     *      provider. Bumping this is a deliberate change.
     */
    public const string API_VERSION = '2023-06-01';

    /**
     * @var int Default `max_tokens` budget per request.
     */
    private const int DEFAULT_MAX_TOKENS = 1024;

    /**
     * @var int Lower HTTP status bound considered "auth failure".
     */
    private const int HTTP_UNAUTHORIZED = 401;

    /**
     * @var int Upper HTTP status bound considered "auth failure".
     */
    private const int HTTP_FORBIDDEN = 403;

    /**
     * @var int HTTP status code for rate-limit.
     */
    private const int HTTP_TOO_MANY_REQUESTS = 429;

    /**
     * Maximum length of an error-body excerpt embedded in thrown
     * `AiProviderException` messages — keeps stack traces readable
     * when the API returns a large HTML or JSON payload on failure.
     */
    private const int ERROR_SNIPPET_MAX_LENGTH = 240;

    /**
     * @param HttpClientInterface $http The transport used to issue
     *        requests. Inject `CurlHttpClient` for the default,
     *        a mock for tests.
     * @param non-empty-string $apiKey The Anthropic API key.
     * @param non-empty-string|null $endpoint Override the default
     *        endpoint URL (e.g., for testing against a local proxy).
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[SensitiveParameter]
        private readonly string $apiKey,
        private readonly ?string $endpoint = null,
    ) {
    }

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $response = $this->http->send($this->buildHttpRequest($request));

        return $this->parseResponse($response, $request);
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
            // Fail-fast: rethrow on the first non-success or malformed body.
            $results[$key] = $this->parseResponse($response, $requests[$key]);
        }

        return $results;
    }

    private function buildHttpRequest(TranslationRequest $request): HttpRequest
    {
        $payload = [
            'model'      => $this->modelFor($request->quality),
            'max_tokens' => self::DEFAULT_MAX_TOKENS,
            'system'     => $this->buildSystemPrompt($request),
            'messages'   => [
                ['role' => 'user', 'content' => $this->buildUserPrompt($request)],
            ],
        ];

        return new HttpRequest(
            method: HttpMethod::POST,
            url: $this->endpoint ?? self::ENDPOINT,
            headers: [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            body: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    private function modelFor(TranslationQuality $quality): string
    {
        return match ($quality) {
            TranslationQuality::FAST     => 'claude-haiku-4-5-20251001',
            TranslationQuality::STANDARD => 'claude-sonnet-4-6',
            TranslationQuality::HIGH     => 'claude-opus-4-7',
        };
    }

    private function buildSystemPrompt(TranslationRequest $request): string
    {
        $base = 'You are a professional translator. Translate user-provided text accurately, '
            . 'preserving placeholders (e.g., %name%, {count}, %s) exactly as they appear. '
            . 'Reply with a single JSON object — no prose, no markdown, no code fences.';

        if ($request->sourcePluralText !== null) {
            $categories = implode(', ', array_map(
                static fn (PluralCategory $c): string => '"' . $c->value . '"',
                PluralCategory::orderedList(),
            ));

            return $base . ' When a plural source is provided, return every CLDR plural form of '
                . 'the target locale that applies, keyed by category (one of: ' . $categories . '). '
                . 'Always include the "other" category. Use this JSON shape: '
                . '{"translatedText": "<singular>", "plurals": {"one": "...", "other": "...", ...}}.';
        }

        return $base . ' For a singular request, use this JSON shape: '
            . '{"translatedText": "<translation>"}.';
    }

    private function buildUserPrompt(TranslationRequest $request): string
    {
        $parts = [
            sprintf('Translate from %s to %s.', $request->sourceLocale, $request->targetLocale),
            sprintf('Source: "%s"', $request->sourceText),
        ];
        if ($request->sourcePluralText !== null) {
            $parts[] = sprintf('Plural source: "%s"', $request->sourcePluralText);
        }
        if ($request->context !== null) {
            $parts[] = sprintf('Context: %s', $request->context);
        }

        return implode("\n", $parts);
    }

    private function parseResponse(HttpResponse $response, TranslationRequest $request): TranslationResult
    {
        $this->assertSuccessful($response);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AiProviderException(
                'Claude returned a body that is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new AiProviderException('Claude response is missing the "content" array.');
        }

        $text = $this->extractTextBlock($decoded['content']);
        $structured = $this->decodeStructuredText($text);

        $singular = is_string($structured['translatedText'] ?? null) ? $structured['translatedText'] : '';
        $plurals = $this->decodePlurals($structured['plurals'] ?? null, $request);

        $metadata = [];
        if (isset($decoded['model']) && is_string($decoded['model'])) {
            $metadata['model'] = $decoded['model'];
        }
        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            $metadata['usage'] = $decoded['usage'];
        }

        return new TranslationResult(
            translatedText: $singular,
            translatedPluralForms: $plurals,
            metadata: $metadata,
        );
    }

    /**
     * @param array<int|string, mixed> $contentBlocks
     */
    private function extractTextBlock(array $contentBlocks): string
    {
        foreach ($contentBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                return $block['text'];
            }
        }

        throw new AiProviderException('Claude response did not contain a "text" content block.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeStructuredText(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AiProviderException(
                'Claude response text is not the expected JSON envelope: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new AiProviderException(
                'Claude response text decoded to a non-object value.',
            );
        }

        return $decoded;
    }

    /**
     * @return array<value-of<PluralCategory>, string>
     */
    private function decodePlurals(mixed $raw, TranslationRequest $request): array
    {
        if ($request->sourcePluralText === null || !is_array($raw)) {
            return [];
        }

        $validKeys = array_map(
            static fn (PluralCategory $c): string => $c->value,
            PluralCategory::orderedList(),
        );

        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !in_array($key, $validKeys, true)) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        if ($result !== [] && !isset($result[PluralCategory::OTHER->value])) {
            throw new AiProviderException(
                'Claude returned plural forms without the mandatory "other" category.',
            );
        }

        return $result;
    }

    private function assertSuccessful(HttpResponse $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }
        if (in_array($response->statusCode, [self::HTTP_UNAUTHORIZED, self::HTTP_FORBIDDEN], true)) {
            throw new AiAuthenticationException(sprintf(
                'Claude rejected the credentials (HTTP %d). Body: %s',
                $response->statusCode,
                $this->snippet($response->body),
            ));
        }
        if ($response->statusCode === self::HTTP_TOO_MANY_REQUESTS) {
            throw new AiRateLimitException(sprintf(
                'Claude rate limit hit (HTTP 429). Body: %s',
                $this->snippet($response->body),
            ));
        }

        throw new AiProviderException(sprintf(
            'Claude returned HTTP %d. Body: %s',
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
