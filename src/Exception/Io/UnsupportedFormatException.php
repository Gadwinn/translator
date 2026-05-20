<?php

declare(strict_types=1);

namespace G\Translator\Exception\Io;

/**
 * Thrown when the auto-detection mechanism cannot map a source to any
 * registered parser/serializer.
 *
 * Typical triggers:
 * - File extension is unknown to the detector.
 * - Content inspection failed to identify a known signature.
 * - The detector registry has no driver for the requested format.
 *
 * Consumers facing this exception SHOULD either explicitly inject the
 * correct parser/serializer, or register a custom driver in the detector.
 */
final class UnsupportedFormatException extends IoException
{
}
