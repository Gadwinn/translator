<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy\Handler;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Engine\Strategy\MergeStrategyHandlerInterface;
use G\Translator\Enum\MergeStrategy;

/**
 * First-write-wins handler.
 *
 * Implements `MergeStrategy::KEEP_EXISTING`: the incoming entry is
 * silently discarded on collision. Use when stacked sources are
 * declared from highest to lowest priority and the first hit
 * should win (e.g., user preferences first, defaults last).
 *
 * Stateless and reusable.
 */
final class KeepExistingHandler implements MergeStrategyHandlerInterface
{
    public function resolve(
        TranslationEntryInterface $existing,
        TranslationEntryInterface $incoming,
    ): TranslationEntryInterface {
        return $existing;
    }

    public function strategy(): MergeStrategy
    {
        return MergeStrategy::KEEP_EXISTING;
    }
}
