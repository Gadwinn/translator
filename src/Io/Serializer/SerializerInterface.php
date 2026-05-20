<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\SerializerException;

/**
 * Contract for a translation file serializer.
 *
 * A serializer is the mirror of a `ParserInterface`: it knows how
 * to take an in-memory `TranslationBag` and produce a string
 * payload in a specific format (`po`, `json`, `xliff`…).
 *
 * Serializers MUST be stateless and reusable. They MUST NOT mutate
 * the bag they receive.
 *
 * Note on responsibility scope (SRP): a serializer ONLY produces
 * the string payload. Persisting that string (writing to a file,
 * sending it over HTTP, hashing it, etc.) is the caller's
 * responsibility. This decoupling makes serializers trivially
 * testable and reusable across storage backends.
 *
 * @see \G\Translator\Bag\TranslationBagInterface
 * @see \G\Translator\Exception\Io\SerializerException
 */
interface SerializerInterface
{
    /**
     * Serializes the given bag into a string in this serializer's
     * native format.
     *
     * The `$domain` parameter, when provided, restricts the output
     * to entries belonging to that single domain. When `null`, all
     * domains present in the bag are serialized (the exact layout
     * — single file with separators, grouped sections, etc. — is
     * implementation-defined).
     *
     * @param TranslationBagInterface $bag The bag to serialize.
     * @param non-empty-string|null $domain Optional domain filter.
     *        Default: `null` (serialize all domains).
     *
     * @return string The serialized payload. Never `null`; an
     *         empty string MAY be returned when the bag (or the
     *         filtered domain) contains no entries.
     *
     * @throws SerializerException When the bag contains data that
     *         cannot be expressed in the target format (e.g.,
     *         plural forms unsupported by the format, invalid
     *         characters for the format's encoding, structural
     *         constraints violated).
     */
    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string;

    /**
     * Returns the format this serializer produces.
     *
     * Used by the auto-detection layer to register serializers and
     * by diagnostic output to report which serializer was selected
     * for a given target.
     */
    public function format(): Format;
}
