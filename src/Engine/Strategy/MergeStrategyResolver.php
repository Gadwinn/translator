<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy;

use G\Translator\Engine\Strategy\Handler\KeepExistingHandler;
use G\Translator\Engine\Strategy\Handler\ReplaceHandler;
use G\Translator\Engine\Strategy\Handler\ThrowOnConflictHandler;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\UnknownMergeStrategyException;

/**
 * Canonical implementation of `MergeStrategyResolverInterface`.
 *
 * Pre-registers the three built-in handlers eagerly in the
 * constructor:
 *
 *   - `MergeStrategy::THROW_ON_CONFLICT` → `ThrowOnConflictHandler`
 *   - `MergeStrategy::REPLACE`           → `ReplaceHandler`
 *   - `MergeStrategy::KEEP_EXISTING`     → `KeepExistingHandler`
 *
 * Consumers MAY call `register()` afterwards to swap any of these
 * for a custom handler (e.g., a content-aware merger). The
 * registration is keyed by the enum's backing string value so that
 * each enum case maps to exactly one handler.
 */
final class MergeStrategyResolver implements MergeStrategyResolverInterface
{
    /**
     * @var array<value-of<MergeStrategy>, MergeStrategyHandlerInterface>
     */
    private array $handlers = [];

    public function __construct()
    {
        $this->register(new ThrowOnConflictHandler());
        $this->register(new ReplaceHandler());
        $this->register(new KeepExistingHandler());
    }

    public function register(MergeStrategyHandlerInterface $handler): void
    {
        $this->handlers[$handler->strategy()->value] = $handler;
    }

    public function resolve(MergeStrategy $strategy): MergeStrategyHandlerInterface
    {
        if (!isset($this->handlers[$strategy->value])) {
            throw new UnknownMergeStrategyException(sprintf(
                'No handler registered for merge strategy "%s".',
                $strategy->value,
            ));
        }

        return $this->handlers[$strategy->value];
    }

    public function has(MergeStrategy $strategy): bool
    {
        return isset($this->handlers[$strategy->value]);
    }
}
