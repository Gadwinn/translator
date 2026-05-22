<?php

declare(strict_types=1);

namespace G\Translator\Io\Source;

use G\Translator\Exception\Io\SourceNotFoundException;

/**
 * `SourceInterface` implementation backed by a PHP stream resource.
 *
 * Accepts any open stream resource (`fopen()`, `php://input`,
 * `php://memory`, tmpfiles, …). The content is read in full the
 * first time `read()` is called and cached internally — this
 * guarantees idempotent reads even on non-seekable streams
 * (`php://input`, network sockets, pipes).
 *
 * The class does NOT close the underlying resource; resource
 * lifecycle is the caller's responsibility. This matches PHP's
 * convention that whoever opens a stream is also in charge of
 * closing it.
 *
 * Because resources have no meaningful filename, `extension()`
 * always returns `null` unless the caller supplied one through
 * the constructor.
 */
final class ResourceSource implements SourceInterface
{
    /**
     * @var resource|null The wrapped stream resource. Set to `null`
     *      after the first successful `read()` so the resource can
     *      be garbage-collected if the caller is done with it.
     */
    private mixed $resource;

    /**
     * @var non-empty-string
     */
    private readonly string $identifier;

    /**
     * @var non-empty-string|null
     */
    private readonly ?string $extension;

    private ?string $cachedContent = null;

    /**
     * @param resource $resource An open stream resource.
     * @param non-empty-string|null $identifier Optional explicit
     *        identifier. Defaults to `"resource://<id>"`.
     * @param non-empty-string|null $extension Optional extension
     *        without leading dot.
     *
     * @throws SourceNotFoundException When the argument is not a
     *         valid open stream resource.
     */
    public function __construct(
        mixed $resource,
        ?string $identifier = null,
        ?string $extension = null,
    ) {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new SourceNotFoundException(
                'ResourceSource requires an open stream resource.',
            );
        }

        $this->resource = $resource;
        $this->identifier = $identifier ?? sprintf('resource://%d', get_resource_id($resource));
        $this->extension = $extension;
    }

    public function read(): string
    {
        if ($this->cachedContent !== null) {
            return $this->cachedContent;
        }

        if ($this->resource === null) {
            throw new SourceNotFoundException(
                'ResourceSource has no active stream resource.',
            );
        }

        $content = @stream_get_contents($this->resource);
        if ($content === false) {
            throw new SourceNotFoundException(sprintf(
                'ResourceSource failed to read stream "%s".',
                $this->identifier,
            ));
        }

        $this->cachedContent = $content;
        $this->resource = null;

        return $content;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function extension(): ?string
    {
        if ($this->extension === null) {
            return null;
        }

        return strtolower($this->extension);
    }
}
