<?php

declare(strict_types=1);

namespace G\Translator\Exception\Engine;

/**
 * Thrown when an unknown or unregistered merge strategy is requested.
 *
 * Typical triggers:
 * - A string-based strategy identifier does not map to any registered
 *   `MergeStrategy` case.
 * - A custom strategy resolver was expected but is not bound.
 */
final class UnknownMergeStrategyException extends EngineException
{
}
