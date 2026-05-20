<?php

declare(strict_types=1);

namespace G\Translator\Exception\Bridge;

use G\Translator\Exception\TranslatorExceptionInterface;
use RuntimeException;

/**
 * Abstract base class for every exception thrown by external bridges
 * (AI providers, remote translation services, third-party integrations).
 *
 * Covers failures that originate from outside the package's own logic,
 * typically network or remote-API related.
 */
abstract class BridgeException extends RuntimeException implements TranslatorExceptionInterface
{
}
