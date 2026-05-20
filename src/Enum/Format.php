<?php

declare(strict_types=1);

namespace G\Translator\Enum;

/**
 * Translation file formats natively supported by the package.
 *
 * The string-backed value of each case is the canonical identifier
 * used by the auto-detection layer, configuration files, and the
 * registry of parsers/serializers. It SHOULD match the conventional
 * lowercase short name of the format (e.g., `po`, not `Portable
 * Object`).
 *
 * Extending the package with a custom format does NOT require adding
 * a case here: third-party parsers/serializers register themselves
 * directly against the detector via their own identifiers. This enum
 * lists only the formats shipped out of the box.
 *
 * @see \G\Translator\Exception\Io\UnsupportedFormatException
 */
enum Format: string
{
    /**
     * GNU Gettext Portable Object.
     * Reference format for desktop/CLI i18n; supports plurals,
     * context (msgctxt), references, flags and translator comments.
     */
    case PO = 'po';

    /**
     * OASIS XLIFF (XML Localization Interchange File Format).
     * Industry standard for professional localization workflows.
     * Implementations SHOULD support both 1.2 and 2.0.
     */
    case XLIFF = 'xliff';

    /**
     * YAML Ain't Markup Language.
     * Human-friendly, widely used in modern PHP frameworks.
     */
    case YAML = 'yaml';

    /**
     * JavaScript Object Notation.
     * Generic, also used by JavaScript/TypeScript front-ends.
     */
    case JSON = 'json';

    /**
     * Native PHP array file returning `array<string, string>`.
     * Fastest to load (OPcache-friendly), zero-parsing overhead.
     */
    case PHP = 'php';

    /**
     * Variant of JSON shaped for Angular i18n tooling
     * (ngx-translate flat dictionary).
     */
    case ANGULAR_JSON = 'angular_json';

    /**
     * Returns the conventional file extensions associated with this
     * format, in lowercase, without the leading dot.
     *
     * Used by extension-based auto-detection. The first element is
     * the canonical extension; subsequent ones are accepted aliases.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::PO => ['po', 'pot'],
            self::XLIFF => ['xlf', 'xliff'],
            self::YAML => ['yaml', 'yml'],
            self::JSON => ['json'],
            self::PHP => ['php'],
            self::ANGULAR_JSON => ['json'],
        };
    }

    /**
     * Attempts to resolve a `Format` from a file extension
     * (case-insensitive, with or without leading dot).
     *
     * Returns `null` when no native format matches. Consumers SHOULD
     * fall through to a custom detector registry before raising
     * `UnsupportedFormatException`.
     *
     * Note on ambiguity: `ANGULAR_JSON` shares the `.json` extension
     * with `JSON`. Extension-based detection alone CANNOT disambiguate
     * them; this method returns `JSON` for `.json` and relies on
     * content inspection (or explicit selection) to detect the
     * Angular variant.
     */
    public static function tryFromExtension(string $extension): ?self
    {
        $normalized = strtolower(ltrim($extension, '.'));

        return match ($normalized) {
            'po', 'pot' => self::PO,
            'xlf', 'xliff' => self::XLIFF,
            'yaml', 'yml' => self::YAML,
            'json' => self::JSON,
            'php' => self::PHP,
            default => null,
        };
    }
}
