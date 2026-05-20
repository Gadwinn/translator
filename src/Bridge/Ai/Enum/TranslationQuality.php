<?php

declare(strict_types=1);

namespace G\Translator\Bridge\Ai\Enum;

/**
 * Declarative hint about the trade-off between translation speed,
 * cost, and quality requested by the caller.
 *
 * The interpretation is provider-specific: each `AiProviderInterface`
 * implementation maps these cases to its own internal knobs
 * (model selection, temperature, max tokens, prompt verbosity,
 * "fastest" vs "best" tier on translation APIs, etc.).
 *
 * Examples of typical mappings:
 *
 * - **OpenAI / Claude**: `FAST` → smaller/cheaper model with a
 *   minimal prompt; `STANDARD` → mid-tier model; `HIGH` → flagship
 *   model with a context-rich prompt.
 *
 * - **DeepL**: `FAST` → default endpoint; `HIGH` → "Pro" model
 *   when the API key supports it.
 *
 * Providers MAY treat unsupported cases as the closest available
 * level rather than throwing, to preserve consumer-side
 * portability.
 */
enum TranslationQuality: string
{
    /**
     * Optimize for latency and cost; quality is acceptable but
     * not prioritized.
     */
    case FAST = 'fast';

    /**
     * Default trade-off: balanced quality, latency, and cost.
     * MUST be the implicit default when callers do not specify
     * a quality.
     */
    case STANDARD = 'standard';

    /**
     * Optimize for quality; latency and cost are secondary.
     * Suitable for production-grade translation passes meant to
     * be reviewed minimally before release.
     */
    case HIGH = 'high';

    /**
     * Returns the canonical default quality used when none is
     * explicitly configured.
     */
    public static function default(): self
    {
        return self::STANDARD;
    }
}
