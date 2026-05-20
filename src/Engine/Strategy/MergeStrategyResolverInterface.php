<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy;

use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\UnknownMergeStrategyException;

/**
 * Registry mapping `MergeStrategy` enum cases to their handler
 * implementations.
 *
 * Acts as the lookup layer between the declarative selector
 * (`MergeStrategy` case) and the effective behavior
 * (`MergeStrategyHandlerInterface`). Built-in handlers (REPLACE,
 * KEEP_EXISTING, THROW_ON_CONFLICT) MUST be pre-registered by
 * default implementations; third parties MAY register additional
 * handlers via `register()`.
 *
 * Implementations MUST be thread-safe for read operations.
 * Mutations (`register()`) MAY require external synchronization.
 *
 * @see \G\Translator\Engine\Strategy\MergeStrategyHandlerInterface
 * @see \G\Translator\Enum\MergeStrategy
 */
interface MergeStrategyResolverInterface
{
    /**
     * Registers a handler against its declared strategy.
     *
     * If a handler is already registered for the same strategy,
     * the new one replaces it. This allows consumers to override
     * built-in handlers without subclassing.
     */
    public function register(MergeStrategyHandlerInterface $handler): void;

    /**
     * Returns the handler bound to the given strategy.
     *
     * @throws UnknownMergeStrategyException When no handler is
     *         registered for the requested strategy.
     */
    public function resolve(MergeStrategy $strategy): MergeStrategyHandlerInterface;

    /**
     * Indicates whether a handler is registered for the given
     * strategy.
     */
    public function has(MergeStrategy $strategy): bool;
}
