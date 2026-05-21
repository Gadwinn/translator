<?php

declare(strict_types=1);

namespace G\Translator\Io\Source;

use G\Translator\Exception\Io\SourceNotFoundException;

/**
 * `SourceInterface` implementation backed by a file on the local
 * filesystem.
 *
 * The path is validated lazily on the first `read()` call rather
 * than in the constructor. This lets consumers construct a source
 * cheaply (no I/O), pass it through their pipeline, and only pay
 * the filesystem hit when the content is actually needed.
 *
 * Constructor-time validation is intentionally limited to "the
 * argument is a non-empty string". Existence, readability, and
 * permission checks happen on `read()`.
 *
 * The content is read once via `file_get_contents()` and not
 * cached: callers who need repeated reads SHOULD cache the result
 * externally. `read()` is therefore not idempotent across
 * external mutations of the file, by design — this enables
 * hot-reload scenarios where a watcher invalidates a cached bag
 * and asks the source to re-read.
 */
final readonly class FileSource implements SourceInterface
{
    /**
     * @var non-empty-string Absolute or relative filesystem path.
     */
    private string $path;

    /**
     * @param string $path The file path. MAY be relative; it is
     *        resolved against the current working directory at
     *        `read()` time. MUST be non-empty; empty strings throw
     *        `SourceNotFoundException`.
     */
    public function __construct(string $path)
    {
        if ($path === '') {
            throw new SourceNotFoundException('FileSource path must not be empty.');
        }

        $this->path = $path;
    }

    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new SourceNotFoundException(sprintf(
                'FileSource path "%s" does not exist or is not a regular file.',
                $this->path,
            ));
        }

        if (!is_readable($this->path)) {
            throw new SourceNotFoundException(sprintf(
                'FileSource path "%s" is not readable (check permissions).',
                $this->path,
            ));
        }

        $content = @file_get_contents($this->path);
        if ($content === false) {
            throw new SourceNotFoundException(sprintf(
                'FileSource failed to read "%s".',
                $this->path,
            ));
        }

        return $content;
    }

    public function identifier(): string
    {
        return $this->path;
    }

    public function extension(): ?string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return null;
        }

        return strtolower($extension);
    }
}
