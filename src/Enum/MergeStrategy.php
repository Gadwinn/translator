<?php

declare(strict_types=1);

namespace G\Translator\Enum;

/**
 * Resolution strategies for key collisions during a stacking/merging
 * operation.
 *
 * When two or more `TranslationBag` instances are merged together,
 * entries sharing the same composite key (id + domain + context)
 * collide. The selected `MergeStrategy` dictates how each collision
 * is resolved.
 *
 * The default is `THROW_ON_CONFLICT`: any collision raises an
 * exception. This enforces a fail-fast policy where every override
 * MUST be an explicit, intentional choice made by the developer
 * (by switching to `REPLACE` or `KEEP_EXISTING`).
 *
 * The string-backed value of each case is its canonical identifier;
 * it MAY be used in configuration files, CLI flags, or serialized
 * state. Do NOT rename a case without bumping a major version.
 *
 * @see \G\Translator\Exception\Engine\MergeException
 * @see \G\Translator\Exception\Engine\UnknownMergeStrategyException
 */
enum MergeStrategy: string
{
    /**
     * Strict mode (default): any collision raises a `MergeException`.
     *
     * Forces the developer to make explicit decisions about
     * overrides instead of silently inheriting last-write-wins
     * behavior. Recommended baseline for CI pipelines and any
     * setup where duplicate keys across sources are a code smell.
     */
    case THROW_ON_CONFLICT = 'throw_on_conflict';

    /**
     * Incoming entry overwrites the existing one (last-write-wins).
     *
     * Use when stacked sources represent overrides from lowest to
     * highest priority (e.g., base translations + tenant overrides).
     * Selecting this strategy is an explicit acknowledgment that
     * later sources are authoritative.
     */
    case REPLACE = 'replace';

    /**
     * Existing entry is preserved; the incoming one is silently
     * discarded (first-write-wins).
     *
     * Use when stacked sources represent fallbacks from highest to
     * lowest priority (e.g., user preferences first, defaults last)
     * and the first hit should be considered authoritative.
     */
    case KEEP_EXISTING = 'keep_existing';

    /**
     * Returns the canonical default strategy used when none is
     * explicitly configured.
     *
     * The default is intentionally strict (`THROW_ON_CONFLICT`):
     * any silent override must be an opt-in decision made by the
     * developer, never a default behavior.
     */
    public static function default(): self
    {
        return self::THROW_ON_CONFLICT;
    }

    /**
     * Indicates whether this strategy mutates the existing entry
     * when a collision occurs.
     *
     * Useful for engine optimizations: a strategy that never mutates
     * (e.g., `KEEP_EXISTING`, `THROW_ON_CONFLICT`) can short-circuit
     * the merge pipeline once a key is known to exist.
     */
    public function mutatesOnCollision(): bool
    {
        return match ($this) {
            self::REPLACE => true,
            self::KEEP_EXISTING, self::THROW_ON_CONFLICT => false,
        };
    }

    /**
     * Indicates whether this strategy is allowed to throw a
     * `MergeException` on a collision.
     *
     * Engines MAY use this flag to bypass try/catch overhead when
     * the active strategy is known to be infallible.
     */
    public function isStrict(): bool
    {
        return $this === self::THROW_ON_CONFLICT;
    }
}
