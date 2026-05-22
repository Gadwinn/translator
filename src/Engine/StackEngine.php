<?php

declare(strict_types=1);

namespace G\Translator\Engine;

use G\Translator\Bag\TranslationBag;
use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Engine\Strategy\MergeStrategyHandlerInterface;
use G\Translator\Engine\Strategy\MergeStrategyResolver;
use G\Translator\Engine\Strategy\MergeStrategyResolverInterface;
use G\Translator\Enum\MergeStrategy;
use G\Translator\Io\Detector\DetectorInterface;

/**
 * Canonical implementation of `StackEngineInterface`.
 *
 * Stores added layers in their original insertion order and snapshots
 * them with a stable sort on every `resolve()` call. The sort is
 * `priority` ascending — lower values fold first, higher values
 * fold last. Equal-priority layers preserve their relative
 * insertion order (PHP's `usort` is intentionally NOT stable, so
 * this implementation uses `array_multisort` to guarantee
 * insertion-order stability when priorities tie).
 *
 * Collision resolution is delegated to a
 * `MergeStrategyResolverInterface` (default:
 * `MergeStrategyResolver`), which itself maps each
 * `MergeStrategy` enum case to a handler. This split keeps the
 * engine Open/Closed: custom handlers can be registered against
 * the resolver without touching the engine.
 *
 * The fold algorithm matches `TranslationBag::merge()` semantically
 * but goes through the handler interface so consumers can plug in
 * a content-aware merger or stricter validator without re-writing
 * the engine.
 */
final class StackEngine implements StackEngineInterface
{
    /**
     * @var list<StackedSource>
     */
    private array $layers = [];

    private readonly MergeStrategyResolverInterface $strategyResolver;

    public function __construct(
        private readonly DetectorInterface $detector,
        ?MergeStrategyResolverInterface $strategyResolver = null,
    ) {
        $this->strategyResolver = $strategyResolver ?? new MergeStrategyResolver();
    }

    public function add(StackedSource $layer): void
    {
        $this->layers[] = $layer;
    }

    /**
     * @return list<StackedSource>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    public function resolve(
        string $locale,
        MergeStrategy $strategy = MergeStrategy::THROW_ON_CONFLICT,
    ): TranslationBagInterface {
        $handler = $this->strategyResolver->resolve($strategy);
        $accumulator = new TranslationBag($locale);

        foreach ($this->sortedLayers() as $layer) {
            $parser = $this->detector->parserFor($layer->source);
            $bag = $parser->parse($layer->source, $locale);
            $this->fold($accumulator, $bag, $handler);
        }

        return $accumulator;
    }

    /**
     * Sorts layers by priority ascending, preserving insertion
     * order across equal-priority ties.
     *
     * @return list<StackedSource>
     */
    private function sortedLayers(): array
    {
        if ($this->layers === []) {
            return [];
        }

        $sorted = $this->layers;
        $priorities = array_map(static fn (StackedSource $l): int => $l->priority, $sorted);
        $indices = range(0, count($sorted) - 1);

        // Sort by priority ASC, then by original index ASC (tiebreaker).
        array_multisort($priorities, SORT_ASC, SORT_NUMERIC, $indices, SORT_ASC, SORT_NUMERIC, $sorted);

        return $sorted;
    }

    private function fold(
        TranslationBagInterface $accumulator,
        TranslationBagInterface $incoming,
        MergeStrategyHandlerInterface $handler,
    ): void {
        foreach ($incoming as $entry) {
            $existing = $accumulator->find($entry->id(), $entry->domain(), $entry->context());
            if ($existing === null) {
                $accumulator->add($entry);
                continue;
            }

            $accumulator->set($handler->resolve($existing, $entry));
        }
    }
}
