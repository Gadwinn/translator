<?php

declare(strict_types=1);

namespace G\Translator\Io\Detector;

use G\Translator\Enum\Format;
use G\Translator\Exception\Io\UnsupportedFormatException;
use G\Translator\Io\Parser\AngularJsonParser;
use G\Translator\Io\Parser\JsonParser;
use G\Translator\Io\Parser\ParserInterface;
use G\Translator\Io\Parser\PhpParser;
use G\Translator\Io\Parser\PoParser;
use G\Translator\Io\Parser\XliffParser;
use G\Translator\Io\Parser\YamlParser;
use G\Translator\Io\Serializer\AngularJsonSerializer;
use G\Translator\Io\Serializer\JsonSerializer;
use G\Translator\Io\Serializer\PhpSerializer;
use G\Translator\Io\Serializer\PoSerializer;
use G\Translator\Io\Serializer\SerializerInterface;
use G\Translator\Io\Serializer\XliffSerializer;
use G\Translator\Io\Serializer\YamlSerializer;
use G\Translator\Io\Source\SourceInterface;

/**
 * Canonical implementation of `DetectorInterface`.
 *
 * Acts as both a Factory and a Registry: built-in parsers and
 * serializers are auto-wired in the constructor, so that the
 * common case `new FormatDetector()` is a single line — consumers
 * never have to perform the registration dance themselves.
 *
 * Auto-wiring is intentionally NOT lazy: every built-in parser
 * and serializer is constructed up-front. They are stateless and
 * tiny, so the cost is negligible, and eager construction means
 * any wiring bug surfaces immediately at detector instantiation
 * time rather than at the first lookup.
 *
 * Consumers retain full control:
 *
 *   - `registerParser()` / `registerSerializer()` REPLACE any
 *     previously registered driver for the same `Format`. Use
 *     this to override a built-in implementation with a custom
 *     one without subclassing.
 *   - Drivers for non-built-in formats (i.e., consumer-defined
 *     `Format` cases would not exist in the package enum; the
 *     out-of-the-box hook is to call `registerParser()` /
 *     `registerSerializer()` with the consumer's own `Format`
 *     value, but since `Format` is a closed enum this is mostly
 *     a build-time customization).
 *
 * ## Detection strategy
 *
 *   1. **Extension-first** — `$source->extension()` is mapped to
 *      a `Format` via `Format::tryFromExtension()`. This handles
 *      the unambiguous cases (`.po`, `.xliff`, `.yaml`, `.php`).
 *   2. **JSON disambiguation** — when the extension resolves to
 *      `Format::JSON`, the detector inspects the content to
 *      distinguish standard `JSON` from `ANGULAR_JSON` (ngx-translate
 *      flat or namespaced dictionary). See `inspectJsonShape()`.
 *   3. **Content-only fallback** — when no extension is present,
 *      the detector tries a few low-cost signatures. Unrecognized
 *      payloads throw `UnsupportedFormatException`.
 */
final class FormatDetector implements DetectorInterface
{
    /**
     * @var array<value-of<Format>, ParserInterface>
     */
    private array $parsers = [];

    /**
     * @var array<value-of<Format>, SerializerInterface>
     */
    private array $serializers = [];

    public function __construct()
    {
        $this->registerBuiltins();
    }

    public function registerParser(ParserInterface $parser): void
    {
        $this->parsers[$parser->format()->value] = $parser;
    }

    public function registerSerializer(SerializerInterface $serializer): void
    {
        $this->serializers[$serializer->format()->value] = $serializer;
    }

    public function detectFormat(SourceInterface $source): Format
    {
        $extension = $source->extension();
        if ($extension !== null) {
            $format = Format::tryFromExtension($extension);
            if ($format === Format::JSON) {
                return $this->resolveJsonFlavor($source);
            }
            if ($format !== null) {
                return $format;
            }
        }

        return $this->detectFromContent($source);
    }

    public function parserFor(SourceInterface $source): ParserInterface
    {
        $format = $this->detectFormat($source);
        if (!isset($this->parsers[$format->value])) {
            throw new UnsupportedFormatException(sprintf(
                'No parser registered for format "%s" (detected for source "%s").',
                $format->value,
                $source->identifier(),
            ));
        }

        return $this->parsers[$format->value];
    }

    public function serializerFor(Format $format): SerializerInterface
    {
        if (!isset($this->serializers[$format->value])) {
            throw new UnsupportedFormatException(sprintf(
                'No serializer registered for format "%s".',
                $format->value,
            ));
        }

        return $this->serializers[$format->value];
    }

    /**
     * Hook for built-in driver auto-wiring.
     *
     * Instantiates every built-in parser/serializer pair eagerly so
     * a wiring bug surfaces at detector construction time rather
     * than at the first lookup. Consumers can override any built-in
     * afterwards via `registerParser()` / `registerSerializer()`.
     */
    private function registerBuiltins(): void
    {
        $this->registerParser(new PoParser());
        $this->registerParser(new JsonParser());
        $this->registerParser(new AngularJsonParser());
        $this->registerParser(new YamlParser());
        $this->registerParser(new PhpParser());
        $this->registerParser(new XliffParser());

        $this->registerSerializer(new PoSerializer());
        $this->registerSerializer(new JsonSerializer());
        $this->registerSerializer(new AngularJsonSerializer());
        $this->registerSerializer(new YamlSerializer());
        $this->registerSerializer(new PhpSerializer());
        $this->registerSerializer(new XliffSerializer());
    }

    /**
     * Distinguishes `JSON` from `ANGULAR_JSON` by inspecting the
     * source's payload.
     *
     * Heuristic: ngx-translate files are dictionaries whose leaves
     * are strings (flat or nested through namespaces). Anything
     * that contains arrays, numbers, booleans, or `null` at any
     * depth is treated as plain `JSON`. Invalid JSON resolves to
     * `JSON` as well so the JSON parser can later surface a
     * structured error.
     */
    private function resolveJsonFlavor(SourceInterface $source): Format
    {
        $payload = $source->read();
        if ($payload === '') {
            return Format::JSON;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return Format::JSON;
        }

        return $this->isAngularShape($decoded) ? Format::ANGULAR_JSON : Format::JSON;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isAngularShape(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        if (array_is_list($value)) {
            return false;
        }

        foreach ($value as $leaf) {
            if (is_string($leaf)) {
                continue;
            }
            if (is_array($leaf) && $this->isAngularShape($leaf)) {
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * Content-only fallback when the source has no extension.
     *
     * Probes a few stable signatures (PO header, JSON braces,
     * XLIFF root element, YAML separator). Anything unrecognized
     * raises `UnsupportedFormatException`.
     */
    private function detectFromContent(SourceInterface $source): Format
    {
        $payload = ltrim($source->read());
        if ($payload === '') {
            throw new UnsupportedFormatException(sprintf(
                'Cannot detect format of empty source "%s".',
                $source->identifier(),
            ));
        }

        if (str_starts_with($payload, 'msgid ') || str_starts_with($payload, '#')) {
            return Format::PO;
        }

        if (str_starts_with($payload, '<?xml') || str_starts_with($payload, '<xliff')) {
            return Format::XLIFF;
        }

        if (str_starts_with($payload, '{') || str_starts_with($payload, '[')) {
            return $this->resolveJsonFlavor($source);
        }

        if (str_starts_with($payload, '<?php')) {
            return Format::PHP;
        }

        if (str_starts_with($payload, '---') || preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*\s*:/m', $payload) === 1) {
            return Format::YAML;
        }

        throw new UnsupportedFormatException(sprintf(
            'Could not detect a supported format for source "%s".',
            $source->identifier(),
        ));
    }
}
