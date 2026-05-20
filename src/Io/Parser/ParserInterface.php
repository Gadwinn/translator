<?php

declare(strict_types=1);

namespace G\Translator\Io\Parser;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\ParserException;
use G\Translator\Io\Source\SourceInterface;

/**
 * Contract for a translation file parser.
 *
 * A parser is a Strategy that knows how to deserialize the payload
 * of a single, specific format (`po`, `json`, `xliff`…) into the
 * package's canonical in-memory representation, the
 * `TranslationBag`.
 *
 * Parsers MUST be stateless and reusable: a single instance can be
 * called multiple times with different sources, possibly in
 * parallel. They MUST NOT mutate the source they receive.
 *
 * Implementations are typically registered against a `Detector` so
 * that the runtime can auto-select them based on the source's
 * format. Direct instantiation is also fully supported for
 * consumers who want to pin a specific parser.
 *
 * @see \G\Translator\Io\Source\SourceInterface
 * @see \G\Translator\Bag\TranslationBagInterface
 * @see \G\Translator\Exception\Io\ParserException
 */
interface ParserInterface
{
    /**
     * Parses the given source and returns a populated translation
     * bag.
     *
     * The `$locale` parameter is informational: it tags every
     * entry produced by the parse with its locale of origin.
     * Implementations MUST NOT attempt to validate the locale's
     * BCP-47 grammar (that is the runtime's responsibility); they
     * only propagate it.
     *
     * The `$domain` parameter scopes the produced entries to a
     * logical domain (e.g., `messages`, `validators`, `admin`).
     * Default: `'messages'`.
     *
     * @param SourceInterface $source The source to read from.
     * @param non-empty-string $locale The locale of the entries
     *        produced by this parse (e.g., `fr-FR`).
     * @param non-empty-string $domain The domain to assign to the
     *        produced entries. Default: `'messages'`.
     *
     * @return TranslationBagInterface A bag populated with the
     *         parsed entries. Never `null`; an empty bag is
     *         returned when the source is empty.
     *
     * @throws ParserException When the source payload is malformed,
     *         uses an unsupported variant, or cannot be decoded
     *         (invalid encoding, broken structure, missing
     *         required field, etc.).
     */
    public function parse(
        SourceInterface $source,
        string $locale,
        string $domain = 'messages',
    ): TranslationBagInterface;

    /**
     * Returns the format this parser handles.
     *
     * Used by the auto-detection layer to register parsers and by
     * diagnostic output to report which parser was selected for a
     * given source.
     */
    public function format(): Format;
}
