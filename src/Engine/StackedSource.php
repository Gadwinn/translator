<?php

declare(strict_types=1);

namespace G\Translator\Engine;

use G\Translator\Io\Source\SourceInterface;

/**
 * Immutable pairing of a `SourceInterface` and a priority value.
 *
 * Represents one layer of the translation stack. The stack engine
 * resolves all layers in ascending priority order: a layer with
 * a higher priority value is applied LAST, and therefore "wins"
 * collisions under the `REPLACE` strategy.
 *
 * This Value Object exists to give the stack a typed, named
 * shape rather than an anonymous associative array, enabling
 * compile-time type checks and future extension (e.g., tags,
 * conditional enabling) without breaking the engine signature.
 *
 * @see \G\Translator\Engine\StackEngineInterface
 * @see \G\Translator\Io\Source\SourceInterface
 */
final readonly class StackedSource
{
    /**
     * @param SourceInterface $source The translation source to load.
     * @param int $priority The merge priority of this layer.
     *        Higher value = applied later = overrides lower layers
     *        under collision-mutating strategies (e.g., `REPLACE`).
     *        MAY be negative. Default convention: 0 for the base
     *        layer, positive values for overrides.
     */
    public function __construct(
        public SourceInterface $source,
        public int $priority = 0,
    ) {
    }
}
