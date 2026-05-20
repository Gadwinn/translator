<?php

declare(strict_types=1);

namespace G\Translator\Io\Source;

use G\Translator\Exception\Io\SourceNotFoundException;

/**
 * Abstraction over the physical origin of translation data.
 *
 * A `Source` represents WHERE the translation data comes from
 * (a file on disk, an in-memory string, an open PHP resource, an
 * HTTP body, etc.) without committing to any specific format.
 *
 * Implementations MUST be idempotent on `read()`: calling it twice
 * MUST return the same content as long as the underlying resource
 * has not been mutated externally. Implementations relying on
 * non-seekable streams (e.g., `php://input`) SHOULD cache the
 * content on first read.
 *
 * @see \G\Translator\Exception\Io\SourceNotFoundException
 */
interface SourceInterface
{
    /**
     * Reads and returns the full content of the source as a string.
     *
     * The returned string is the raw, unparsed payload. Encoding
     * conversion (e.g., UTF-16 → UTF-8) is the responsibility of
     * the parser, not the source.
     *
     * @return string The full payload. May be empty.
     *
     * @throws SourceNotFoundException When the source cannot be
     *         located, opened, or read (missing file, denied
     *         permission, broken stream, etc.).
     */
    public function read(): string;

    /**
     * Returns a human-readable identifier for this source.
     *
     * Used for error messages, logging, and the auto-detection
     * layer (when extension-based detection is involved). Typical
     * values: absolute file path, URL, `string://<hash>`,
     * `resource://php://input`.
     *
     * MUST be stable across multiple calls and SHOULD be unique
     * enough to identify the source in diagnostic output.
     *
     * @return non-empty-string
     */
    public function identifier(): string;

    /**
     * Returns the conventional extension of the source, if any.
     *
     * Used by extension-based auto-detection. Implementations
     * backed by a filename MUST return the extension in lowercase
     * without the leading dot (e.g., `po`, `json`).
     * Implementations with no meaningful extension (raw strings,
     * generic resources) MUST return `null`.
     *
     * @return non-empty-string|null
     */
    public function extension(): ?string;
}
