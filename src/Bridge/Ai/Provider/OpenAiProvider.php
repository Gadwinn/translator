<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Provider;

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
 * `AiProviderInterface` implementation backed by OpenAI's Chat
 * Completions API with JSON mode (`response_format`).
 *
 * Model selection follows `TranslationQuality`:
 *
 *   - `FAST`     → `gpt-4o-mini`
 *   - `STANDARD` → `gpt-4o`
 *   - `HIGH`     → `gpt-4o`
 *
 * Plural support: leverages OpenAI's `response_format: json_object`
 * mode. The provider prompts the model for an envelope containing
 * a singular `translatedText` and, when `sourcePluralText` is
 * non-null, every CLDR plural form keyed by category.
 *
 * ## Batch failure policy
 *
 * `translateBatch()` is **fail-fast**: any non-success response (or
 * malformed JSON envelope) propagates immediately and successfully
 * translated requests prior to the failure are discarded. Consumers
 * needing partial-success semantics SHOULD wrap individual
 * `translate()` calls.
 *
 * ## Credentials
 *
 * Constructor-injected. The provider NEVER reads `getenv()`.
 */
final class OpenAiProvider implements AiProviderInterface
{
    /**
     * @var non-empty-string Stable provider identifier.
     */
    public const string PROVIDER_NAME = 'openai';

    /**
     * @var non-empty-string OpenAI Chat Completions endpoint.
     */
    public const string ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const int HTTP_UNAUTHORIZED = 401;
    private const int HTTP_FORBIDDEN = 403;
    private const int HTTP_TOO_MANY_REQUESTS = 429;

    /**
     * @param HttpClientInterface $http Transport used to issue
     *        requests. Inject `CurlHttpClient` for the default,
     *        a mock for tests.
     * @param non-empty-string $apiKey The OpenAI API key.
     * @param non-empty-string|null $endpoint Override the default
     *        endpoint URL (e.g., for testing against a local proxy).
     */
    public function __construct(
        private readonly HttpClientInterface $http,
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
            $results[$key] = $this->parseResponse($response, $requests[$key]);
        }

        return $results;
    }

    private function buildHttpRequest(TranslationRequest $request): HttpRequest
    {
        $payload = [
            'model'    => $this->modelFor($request->quality),
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($request)],
                ['role' => 'user',   'content' => $this->buildUserPrompt($request)],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        return new HttpRequest(
            method: HttpMethod::POST,
            url: $this->endpoint ?? self::ENDPOINT,
            headers: [
                'authorization' => 'Bearer ' . $this->apiKey,
                'content-type'  => 'application/json',
            ],
            body: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    private function modelFor(TranslationQuality $quality): string
    {
        return match ($quality) {
            TranslationQuality::FAST     => 'gpt-4o-mini',
            TranslationQuality::STANDARD => 'gpt-4o',
            TranslationQuality::HIGH     => 'gpt-4o',
        };
    }

    private function buildSystemPrompt(TranslationRequest $request): string
    {
        $base = 'You are a professional translator. Translate user-provided text accurately, '
            . 'preserving placeholders (e.g., %name%, {count}, %s) exactly as they appear. '
            . 'Your reply MUST be a single JSON object — no prose, no markdown.';

        if ($request->sourcePluralText !== null) {
            $categories = implode(', ', array_map(
                static fn (PluralCategory $c): string => '"' . $c->value . '"',
                PluralCategory::orderedList(),
            ));

            return $base . ' When a plural source is provided, return every CLDR plural form of '
                . 'the target locale that applies, keyed by category (one of: ' . $categories . '). '
                . 'Always include the "other" category. Schema: '
                . '{"translatedText": "<singular>", "plurals": {"one": "...", "other": "...", ...}}.';
        }

        return $base . ' Schema for a singular request: {"translatedText": "<translation>"}.';
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
                'OpenAI returned a body that is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded) || !isset($decoded['choices']) || !is_array($decoded['choices'])) {
            throw new AiProviderException('OpenAI response is missing the "choices" array.');
        }

        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice) || !isset($choice['message']) || !is_array($choice['message'])) {
            throw new AiProviderException('OpenAI response choice has no "message".');
        }

        $content = $choice['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new AiProviderException('OpenAI response message has no string "content".');
        }

        $structured = $this->decodeStructuredText($content);
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
                'OpenAI message content is not the expected JSON envelope: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new AiProviderException('OpenAI message content decoded to a non-object value.');
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
            if (!is_string($key) || !in_array($key, $validKeys, true) || !is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        if ($result !== [] && !isset($result[PluralCategory::OTHER->value])) {
            throw new AiProviderException(
                'OpenAI returned plural forms without the mandatory "other" category.',
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
                'OpenAI rejected the credentials (HTTP %d). Body: %s',
                $response->statusCode,
                $this->snippet($response->body),
            ));
        }
        if ($response->statusCode === self::HTTP_TOO_MANY_REQUESTS) {
            throw new AiRateLimitException(sprintf(
                'OpenAI rate limit hit (HTTP 429). Body: %s',
                $this->snippet($response->body),
            ));
        }

        throw new AiProviderException(sprintf(
            'OpenAI returned HTTP %d. Body: %s',
            $response->statusCode,
            $this->snippet($response->body),
        ));
    }

    private function snippet(string $body): string
    {
        $maxLength = 240;
        if (strlen($body) <= $maxLength) {
            return $body;
        }

        return substr($body, 0, $maxLength) . '...';
    }
}
