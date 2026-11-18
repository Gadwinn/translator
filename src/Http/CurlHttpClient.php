<?php

declare(strict_types=1);

namespace G\Translator\Http;

use CurlHandle;
use G\Translator\Exception\Bridge\HttpTimeoutException;
use G\Translator\Exception\Bridge\HttpTransportException;

/**
 * Default `HttpClientInterface` implementation backed by `ext-curl`.
 *
 * Single-request calls use `curl_exec`; batch calls use
 * `curl_multi_*` for genuine parallelism — this is the whole point
 * of the `sendMany()` method, and a naive sequential loop would be
 * non-compliant with the interface's contract.
 *
 * ## Security defaults
 *
 *   - **HTTPS-only**: requests with a non-`https://` URL raise
 *     `HttpTransportException`. Constructor flag `$allowHttp` opts
 *     out (useful for local mock servers; production code SHOULD
 *     keep the default).
 *   - **No redirect following** (`CURLOPT_FOLLOWLOCATION` is off):
 *     AI providers don't issue meaningful redirects on these
 *     endpoints, and following them silently is a security risk.
 *   - **`CURLOPT_SSL_VERIFYPEER`** stays on. Disabling is not
 *     supported by this implementation; users with self-signed
 *     certs SHOULD write their own adapter.
 *
 * ## Error mapping
 *
 *   - `CURLE_OPERATION_TIMEDOUT` → `HttpTimeoutException` (distinct
 *     subtype so retry/backoff code can match on it).
 *   - Any other transport-level failure → `HttpTransportException`.
 *   - HTTP-level errors (4xx, 5xx) are NEVER thrown — they surface
 *     through `HttpResponse::statusCode`.
 *
 * ## Batch failure policy
 *
 * `sendMany()` first awaits ALL handles, collects per-request
 * outcomes, then raises a single `HttpTransportException` summarising
 * the failed keys if any request did not complete at the transport
 * layer. Successful responses for the surviving requests are
 * intentionally NOT returned alongside the exception — the contract
 * is "all or nothing" for callers reading per-key results.
 *
 * @codeCoverageIgnore
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @var int Default connect timeout (seconds). Matches the
     *      response timeout's defensive baseline.
     */
    private const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @var int Sleep budget for each `curl_multi_select` cycle in
     *      microseconds. Tight enough to keep latency low, loose
     *      enough to avoid burning CPU when sockets are idle.
     */
    private const int MULTI_SELECT_TIMEOUT_MICROSECONDS = 100000;

    /**
     * @var int Microseconds per second — divisor that converts the
     *      microsecond budget above into the fractional-second value
     *      expected by `curl_multi_select()`.
     */
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    public function __construct(
        private readonly bool $allowHttp = false,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->validateUrl($request->url);

        // Since PHP 8.0, curl handles are objects auto-released on
        // scope exit; `curl_close` is deprecated as a no-op in 8.5.
        $handle = $this->buildHandle($request);
        $rawResponse = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($rawResponse === false || !is_string($rawResponse) || $errorNumber !== 0) {
            $this->throwTransportError($request->url, $errorNumber, $errorMessage);
        }

        return $this->parseResponse($rawResponse, $headerSize, $statusCode);
    }

    /**
     * @param array<array-key, HttpRequest> $requests
     *
     * @return array<array-key, HttpResponse>
     */
    public function sendMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        foreach ($requests as $request) {
            $this->validateUrl($request->url);
        }

        $multi = curl_multi_init();

        /** @var array<array-key, CurlHandle> $handles */
        $handles = [];
        foreach ($requests as $key => $request) {
            $handle = $this->buildHandle($request);
            $handles[$key] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        $stillRunning = 0;
        do {
            $status = curl_multi_exec($multi, $stillRunning);
            if ($stillRunning > 0) {
                curl_multi_select($multi, self::MULTI_SELECT_TIMEOUT_MICROSECONDS / self::MICROSECONDS_PER_SECOND);
            }
        } while ($stillRunning > 0 && $status === CURLM_OK);

        /** @var array<array-key, HttpResponse> $responses */
        $responses = [];
        /** @var array<array-key, array{url: string, errno: int, msg: string}> $errors */
        $errors = [];

        foreach ($handles as $key => $handle) {
            $rawResponse = curl_multi_getcontent($handle);
            $errorNumber = curl_errno($handle);
            $errorMessage = curl_error($handle);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $url = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

            curl_multi_remove_handle($multi, $handle);

            if (!is_string($rawResponse) || $errorNumber !== 0) {
                $errors[$key] = ['url' => $url, 'errno' => $errorNumber, 'msg' => $errorMessage];
                continue;
            }

            $responses[$key] = $this->parseResponse($rawResponse, $headerSize, $statusCode);
        }

        curl_multi_close($multi);

        if ($errors !== []) {
            $this->throwBatchError($errors);
        }

        return $responses;
    }

    /**
     * @param non-empty-string $url
     */
    private function validateUrl(string $url): void
    {
        if ($this->allowHttp) {
            return;
        }
        if (!str_starts_with($url, 'https://')) {
            throw new HttpTransportException(sprintf(
                'CurlHttpClient: non-HTTPS URL rejected ("%s"). Pass allowHttp=true to opt in.',
                $url,
            ));
        }
    }

    private function buildHandle(HttpRequest $request): CurlHandle
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('CurlHttpClient: failed to initialize curl handle.');
        }

        $options = [
            CURLOPT_URL            => $request->url,
            CURLOPT_CUSTOMREQUEST  => $request->method->value,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => $this->encodeHeaders($request->headers),
        ];

        if ($request->method->hasBody() && $request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * @param array<non-empty-string, string> $headers
     *
     * @return list<non-empty-string>
     */
    private function encodeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $result[] = $name . ': ' . $value;
        }

        return $result;
    }

    private function parseResponse(string $raw, int $headerSize, int $statusCode): HttpResponse
    {
        $headerString = $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
        $body = $headerSize > 0 ? substr($raw, $headerSize) : $raw;

        return new HttpResponse(
            statusCode: $statusCode,
            headers: $this->parseHeaders($headerString),
            body: $body,
        );
    }

    /**
     * Parses raw CRLF-separated headers into a lowercase-keyed map.
     *
     * Multiple HTTP/* status lines (caused by, e.g., proxy chains)
     * are silently dropped — only the actual header fields are kept.
     *
     * @return array<non-empty-string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                continue;
            }
            $colon = strpos($trimmed, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($trimmed, 0, $colon)));
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim(substr($trimmed, $colon + 1));
        }

        return $headers;
    }

    private function throwTransportError(string $url, int $errorNumber, string $errorMessage): never
    {
        if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
            throw new HttpTimeoutException(sprintf(
                'CurlHttpClient: request to "%s" timed out (curl error %d: %s).',
                $url,
                $errorNumber,
                $errorMessage,
            ));
        }

        throw new HttpTransportException(sprintf(
            'CurlHttpClient: request to "%s" failed (curl error %d: %s).',
            $url,
            $errorNumber,
            $errorMessage,
        ));
    }

    /**
     * @param array<array-key, array{url: string, errno: int, msg: string}> $errors
     */
    private function throwBatchError(array $errors): never
    {
        $hasTimeout = false;
        $summary = [];
        foreach ($errors as $key => $error) {
            if ($error['errno'] === CURLE_OPERATION_TIMEDOUT) {
                $hasTimeout = true;
            }
            $summary[] = sprintf(
                '[%s] %s (curl %d: %s)',
                (string) $key,
                $error['url'],
                $error['errno'],
                $error['msg'],
            );
        }

        $message = sprintf(
            'CurlHttpClient::sendMany() failed for %d request(s): %s.',
            count($errors),
            implode('; ', $summary),
        );

        if ($hasTimeout) {
            throw new HttpTimeoutException($message);
        }

        throw new HttpTransportException($message);
    }
}
