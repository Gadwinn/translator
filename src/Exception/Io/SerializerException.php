<?php

declare(strict_types=1);

namespace G\Translator\Exception\Io;

/**
 * Thrown when a serializer fails to produce a valid output from a
 * `TranslationBag`.
 *
 * Typical triggers:
 * - Target file/path is not writable.
 * - Encoding step fails (e.g., JSON encoding error, XML schema violation).
 * - Required fields are missing from the bag for the target format.
 *
 * Implementations of `SerializerInterface` MUST throw this exception
 * when serialization cannot complete successfully.
 */
final class SerializerException extends IoException
{
}
