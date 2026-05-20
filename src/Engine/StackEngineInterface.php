<?php

declare(strict_types=1);

namespace G\Translator\Engine;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Exception\Engine\MergeException;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Exception\Io\SourceNotFoundException;
use G\Translator\Exception\Io\UnsupportedFormatException;

/**
 * Orchestrator of the translation stacking pipeline.
 *
 * A `StackEngine` consumes an ordered set of `StackedSource`
 * entries, parses each through the auto-detection layer, and
 * folds the resulting bags into a single consolidated bag using
 * the configured `MergeStrategy`.
 *
 * Pipeline (per call to `resolve()`):
 *   1. Sort layers by ascending priority.
 *   2. For each layer: detect format → parse → obtain bag.
 *   3. Fold each bag into the accumulator under the active
 *      strategy.
 *   4. Return the final bag.
 *
 * The engine is intentionally locale-agnostic: callers provide a
 * locale per `resolve()` call, and only layers whose source can
 * produce data for that locale are considered. Locale filtering
 * is delegated to the parsers themselves (a parser that cannot
 * find data for the requested locale MAY return an empty bag).
 *
 * @see \G\Translator\Engine\StackedSource
 * @see \G\Translator\Enum\MergeStrategy
 * @see \G\Translator\Bag\TranslationBagInterface
 */
interface StackEngineInterface
{
    /**
     * Appends a stacked source to the engine.
     *
     * Order of `add()` calls does not directly determine the
     * merge order: the engine ALWAYS sorts layers by their
     * `StackedSource::$priority` field before resolving. To
     * influence the order, set priority values explicitly.
     */
    public function add(StackedSource $layer): void;

    /**
     * Returns all stacked layers in their original add order.
     *
     * The returned list is a snapshot: mutating it does not
     * affect the engine.
     *
     * @return list<StackedSource>
     */
    public function layers(): array;

    /**
     * Resolves all stacked layers into a single consolidated bag
     * for the given locale.
     *
     * @param non-empty-string $locale The target locale
     *        (e.g., `fr-FR`, `en`).
     * @param MergeStrategy $strategy Collision resolution
     *        strategy. Default: `MergeStrategy::default()`
     *        (THROW_ON_CONFLICT).
     *
     * @throws SourceNotFoundException When a layer's source
     *         cannot be read.
     * @throws UnsupportedFormatException When a layer's format
     *         cannot be auto-detected or has no registered
     *         parser.
     * @throws ParserException When a layer's content cannot be
     *         parsed.
     * @throws MergeException When the strategy is
     *         `THROW_ON_CONFLICT` and a collision occurs, or
     *         when a custom handler raises.
     */
    public function resolve(
        string $locale,
        MergeStrategy $strategy = MergeStrategy::THROW_ON_CONFLICT,
    ): TranslationBagInterface;
}
