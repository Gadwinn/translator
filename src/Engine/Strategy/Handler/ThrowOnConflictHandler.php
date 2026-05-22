<?php

declare(strict_types=1);

namespace G\Translator\Engine\Strategy\Handler;

use G\Translator\Bag\TranslationEntryInterface;
use G\Translator\Engine\Strategy\MergeStrategyHandlerInterface;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\MergeException;

/**
 * Strict handler that refuses any collision.
 *
 * Implements the default `MergeStrategy::THROW_ON_CONFLICT`
 * selector: every collision raises a `MergeException`. Callers
 * who reach this code path have failed to declare an explicit
 * override policy, and the package surfaces that fact loudly
 * rather than silently picking a winner.
 *
 * Stateless and reusable across any number of merge operations.
 */
final class ThrowOnConflictHandler implements MergeStrategyHandlerInterface
{
    public function resolve(
        TranslationEntryInterface $existing,
        TranslationEntryInterface $incoming,
    ): TranslationEntryInterface {
        throw new MergeException(sprintf(
            'Merge conflict on key "%s" (domain "%s"); strategy THROW_ON_CONFLICT '
            . 'requires every collision to be resolved by an explicit override policy.',
            $existing->key(),
            $existing->domain(),
        ));
    }

    public function strategy(): MergeStrategy
    {
        return MergeStrategy::THROW_ON_CONFLICT;
    }
}
