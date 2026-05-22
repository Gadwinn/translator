<?php

declare(strict_types=1);

namespace G\Translator\Tests\Stub;

use G\Translator\Bridge\Ai\AiProviderInterface;
use G\Translator\Bridge\Ai\Request\TranslationRequest;
use G\Translator\Bridge\Ai\Result\TranslationResult;

/**
 * Test-only provider that records incoming requests and returns
 * canned results keyed by source text.
 *
 * Lives under `tests/Stub/`; never instantiated by production code.
 */
final class StubAiProvider implements AiProviderInterface
{
    /**
     * @var array<string, TranslationResult> Source text → canned result.
     */
    private array $responses = [];

    /**
     * @var list<TranslationRequest> Captured requests in submission order.
     */
    public array $received = [];

    public function setResponse(string $sourceText, TranslationResult $result): void
    {
        $this->responses[$sourceText] = $result;
    }

    public function name(): string
    {
        return 'stub';
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $this->received[] = $request;

        return $this->responses[$request->sourceText]
            ?? new TranslationResult(translatedText: 'AUTO::' . $request->sourceText);
    }

    /**
     * @param array<array-key, TranslationRequest> $requests
     *
     * @return array<array-key, TranslationResult>
     */
    public function translateBatch(array $requests): array
    {
        $results = [];
        foreach ($requests as $key => $request) {
            $results[$key] = $this->translate($request);
        }

        return $results;
    }
}
