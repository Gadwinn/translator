<?php

declare(strict_types=1);

namespace G\Translator\Exception\Io;

use G\Translator\Exception\TranslatorExceptionInterface;
use RuntimeException;

/**
 * Abstract base class for every exception thrown by the I/O layer.
 *
 * The I/O layer covers reading from sources, parsing input streams,
 * detecting formats, and serializing translation data back to a target
 * output. Any failure happening during these operations MUST extend
 * this class.
 *
 * Extends \RuntimeException because I/O failures are by nature runtime
 * conditions (missing files, malformed content, permission denied) that
 * cannot be detected at development time.
 */
abstract class IoException extends RuntimeException implements TranslatorExceptionInterface
{
}
