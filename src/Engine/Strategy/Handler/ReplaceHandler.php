<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy\Handler;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Engine\Strategy\MergeStrategyHandlerInterface;
use G\Translator\Enum\MergeStrategy;

/**
 * Last-write-wins handler.
 *
 * Implements `MergeStrategy::REPLACE`: the incoming entry always
 * overwrites the existing one. Use when stacked sources are
 * declared from lowest to highest priority and later layers are
 * explicitly authoritative (e.g., base translations + tenant
 * overrides).
 *
 * Stateless and reusable.
 */
final class ReplaceHandler implements MergeStrategyHandlerInterface
{
    public function resolve(
        TranslationEntryInterface $existing,
        TranslationEntryInterface $incoming,
    ): TranslationEntryInterface {
        return $incoming;
    }

    public function strategy(): MergeStrategy
    {
        return MergeStrategy::REPLACE;
    }
}
