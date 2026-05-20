<?php

declare(strict_types=1);

namespace G\Translator\Io\Detector;

use G\Translator\Enum\Format;
use G\Translator\Exception\Io\UnsupportedFormatException;
use G\Translator\Io\Parser\ParserInterface;
use G\Translator\Io\Serializer\SerializerInterface;
use G\Translator\Io\Source\SourceInterface;

/**
 * Auto-detection contract for the I/O layer.
 *
 * A `Detector` acts as a Factory + Registry: third-party parsers
 * and serializers register themselves against it, and the runtime
 * uses it to obtain the right implementation for a given source
 * (parsing) or target format (serializing).
 *
 * Detection strategy is implementation-defined but typically
 * proceeds in this order:
 *   1. Source's `extension()` → lookup in the registry.
 *   2. Content inspection (signature/heuristic) if extension is
 *      ambiguous or missing.
 *   3. Failure → `UnsupportedFormatException`.
 *
 * Implementations MUST be thread-safe for read operations.
 * Mutations (`registerParser`, `registerSerializer`) MAY require
 * external synchronization in concurrent setups.
 *
 * @see \G\Translator\Io\Parser\ParserInterface
 * @see \G\Translator\Io\Serializer\SerializerInterface
 * @see \G\Translator\Exception\Io\UnsupportedFormatException
 */
interface DetectorInterface
{
    /**
     * Registers a parser against its declared format.
     *
     * If a parser is already registered for the same format, the
     * new one replaces it. This is intentional and lets consumers
     * override built-in parsers with their own implementations
     * without subclassing.
     */
    public function registerParser(ParserInterface $parser): void;

    /**
     * Registers a serializer against its declared format.
     *
     * Same override semantics as `registerParser()`.
     */
    public function registerSerializer(SerializerInterface $serializer): void;

    /**
     * Detects the format of the given source.
     *
     * Inspects the source's extension first, then (if necessary)
     * its content. Returns the resolved `Format` on success.
     *
     * @throws UnsupportedFormatException When no registered driver
     *         can handle the source.
     */
    public function detectFormat(SourceInterface $source): Format;

    /**
     * Returns the parser registered for the source's detected
     * format.
     *
     * Convenience method combining detection and lookup so that
     * consumers can write: `$detector->parserFor($source)->parse(...)`.
     *
     * @throws UnsupportedFormatException When no parser is
     *         registered for the source's format.
     */
    public function parserFor(SourceInterface $source): ParserInterface;

    /**
     * Returns the serializer registered for the given format.
     *
     * Unlike `parserFor()`, serialization is driven by an explicit
     * target format chosen by the consumer (there is no "source"
     * to inspect on the write path).
     *
     * @throws UnsupportedFormatException When no serializer is
     *         registered for the requested format.
     */
    public function serializerFor(Format $format): SerializerInterface;
}
