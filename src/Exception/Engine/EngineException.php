<?php

declare(strict_types=1);

namespace G\Translator\Exception\Engine;

use G\Translator\Exception\TranslatorExceptionInterface;
use RuntimeException;

/**
 * Abstract base class for every exception thrown by the stacking and
 * merging engine.
 *
 * Covers failures occurring during the resolution of multiple stacked
 * sources into a single, coherent `TranslationBag`.
 */
abstract class EngineException extends RuntimeException implements TranslatorExceptionInterface
{
}
