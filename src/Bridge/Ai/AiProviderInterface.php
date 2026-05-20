<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai;

use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Bridge\Ai\Result\TranslationResult;
use G\Translator\Exception\Bridge\AiAuthenticationException;
use G\Translator\Exception\Bridge\AiProviderException;
use G\Translator\Exception\Bridge\AiRateLimitException;

/**
 * Contract for an AI-backed translation provider.
 *
 * Covers BOTH categories of providers:
 *
 * - **Translation APIs** (DeepL, Google Translate, AWS Translate):
 *   text in, text out, no instructional context. Providers in
 *   this category typically ignore `TranslationRequest::$context`
 *   and `$sourcePluralText`.
 *
 * - **LLM APIs** (Claude, OpenAI, Mistral, Gemini, local Ollama):
 *   prompt-based, capable of leveraging context and producing
 *   structured outputs (e.g., JSON of all CLDR plural variants).
 *
 * The contract is identical for both: callers do not need to know
 * which category their provider belongs to.
 *
 * Implementations MUST:
 * - Be safe to share across requests (no per-call mutable state).
 * - Translate transport-level failures into one of the three
 *   bridge exceptions documented below.
 * - Surface upstream rate-limit information by throwing
 *   `AiRateLimitException` rather than retrying silently. The
 *   caller decides whether and how to retry.
 *
 * Implementations MAY:
 * - Memoize identical requests in-process (recommended for
 *   `translateBatch()` with internal deduplication).
 * - Combine multiple `TranslationRequest` entries into a single
 *   upstream API call when the provider supports it (cost
 *   efficiency).
 *
 * @see \G\Translator\Bridge\Ai\Request\TranslationRequest
 * @see \G\Translator\Bridge\Ai\Result\TranslationResult
 */
interface AiProviderInterface
{
    /**
     * Returns a stable, human-readable identifier for this
     * provider (e.g., `claude`, `openai`, `deepl`).
     *
     * Used for logging, metrics, and registry lookups. MUST be
     * lowercase, ASCII, and free of whitespace.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Translates a single request and returns its result.
     *
     * @throws AiAuthenticationException When the provider rejects
     *         the credentials (HTTP 401/403, invalid API key,
     *         expired token).
     * @throws AiRateLimitException When the provider reports a
     *         rate-limit or quota exhaustion (HTTP 429, billing
     *         limit reached).
     * @throws AiProviderException For any other provider failure
     *         (malformed response, unexpected status code,
     *         schema mismatch, transport error).
     */
    public function translate(TranslationRequest $request): TranslationResult;

    /**
     * Translates many requests at once.
     *
     * Implementations are STRONGLY encouraged to leverage parallel
     * HTTP execution (via the underlying `HttpClientInterface::sendMany()`)
     * or provider-side batch endpoints when available, since this
     * is the single biggest performance and cost lever when
     * translating large catalogues.
     *
     * The returned array MUST preserve the keys of the input
     * array, so callers can correlate results with their original
     * requests. Order is implementation-defined for parallel
     * execution.
     *
     * Per-request failures policy: when one or more requests in
     * the batch fail, implementations MAY either:
     *   (a) throw immediately on the first failure (fail-fast), or
     *   (b) collect all results and throw at the end with details
     *       about the failed entries.
     * Implementations MUST document their chosen policy.
     *
     * @param array<array-key, TranslationRequest> $requests
     *
     * @return array<array-key, TranslationResult>
     *
     * @throws AiAuthenticationException
     * @throws AiRateLimitException
     * @throws AiProviderException
     */
    public function translateBatch(array $requests): array;
}
