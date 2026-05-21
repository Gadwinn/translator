<?php

declare(strict_types=1);

namespace G\Translator\Tests\Stub;

use G\Translator\Exception\Bridge\BridgeException;
use G\Translator\Http\HttpClientInterface;
use G\Translator\Http\HttpRequest;
use G\Translator\Http\HttpResponse;
use RuntimeException;

/**
 * Test-only `HttpClientInterface` implementation returning canned
 * `HttpResponse` payloads.
 *
 * Lives under `tests/Stub/` so it's part of the test surface, never
 * the production surface. The class is intentionally tolerant of
 * misuse (raising `RuntimeException` rather than a package
 * exception) so test failures surface with stack traces and not as
 * silent package-level errors.
 */
final class StubHttpClient implements HttpClientInterface
{
    /**
     * @var list<HttpResponse> Canned responses, consumed in order.
     */
    private array $queue = [];

    /**
     * @var list<HttpRequest> Captured requests, in submission order.
     */
    public array $sent = [];

    /**
     * @var BridgeException|null Optional exception thrown on the
     *      next call instead of returning a queued response.
     */
    private ?BridgeException $nextThrow = null;

    public function enqueueResponse(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function throwOnNextCall(BridgeException $exception): void
    {
        $this->nextThrow = $exception;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->sent[] = $request;

        if ($this->nextThrow !== null) {
            $exception = $this->nextThrow;
            $this->nextThrow = null;
            throw $exception;
        }

        if ($this->queue === []) {
            throw new RuntimeException('StubHttpClient: no queued response available for send().');
        }

        return array_shift($this->queue);
    }

    /**
     * @param array<array-key, HttpRequest> $requests
     *
     * @return array<array-key, HttpResponse>
     */
    public function sendMany(array $requests): array
    {
        if ($this->nextThrow !== null) {
            $exception = $this->nextThrow;
            $this->nextThrow = null;
            throw $exception;
        }

        $responses = [];
        foreach ($requests as $key => $request) {
            $this->sent[] = $request;
            if ($this->queue === []) {
                throw new RuntimeException(
                    'StubHttpClient: queue exhausted during sendMany() — enqueue one response per request.',
                );
            }
            $responses[$key] = array_shift($this->queue);
        }

        return $responses;
    }
}
