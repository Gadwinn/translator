<?php

declare(strict_types=1);

namespace G\Translator\Bag;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Bag\InvalidEntryException;

/**
 * Canonical, immutable implementation of `TranslationEntryInterface`.
 *
 * Instances are constructed with their full composite key and content
 * at once. Mutators (`withContent`, `withPluralForms`, `withMetadata`)
 * never mutate `$this`; they return a fresh instance carrying the
 * updated state. This guarantees that, once an entry is inserted in
 * a bag, neither its identity nor its observable shape can drift.
 *
 * The composite key uses the recommended encoding from the interface:
 *   `{domain}::{contextOrSentinel}::{id}` with `__none__` as the
 *   sentinel for `null` contexts.
 *
 * Invariants enforced at construction time:
 *   - `id` is non-empty.
 *   - `domain` is non-empty.
 *   - `context`, when not `null`, is non-empty.
 *   - When at least one plural form is present, the mandatory
 *     `PluralCategory::OTHER` key MUST also be present.
 *
 * Violations throw `InvalidEntryException` immediately, preventing
 * a malformed entry from ever entering a `TranslationBag`.
 */
final readonly class TranslationEntry implements TranslationEntryInterface
{
    /**
     * @var non-empty-string Sentinel used in `key()` for entries
     *      without a disambiguation context.
     */
    public const string NO_CONTEXT_SENTINEL = '__none__';

    /**
     * @var non-empty-string Separator used between segments of the
     *      composite key.
     */
    public const string KEY_SEPARATOR = '::';

    /**
     * @var non-empty-string
     */
    private string $id;

    private string $content;

    /**
     * @var non-empty-string
     */
    private string $domain;

    /**
     * @var non-empty-string|null
     */
    private ?string $context;

    /**
     * @var array<value-of<PluralCategory>, string>
     */
    private array $pluralForms;

    /**
     * @var array<non-empty-string, mixed>
     */
    private array $metadata;

    /**
     * @var non-empty-string
     */
    private string $key;

    /**
     * @param string $id The source identifier. MUST be non-empty;
     *        empty strings throw `InvalidEntryException`.
     * @param string $content The translated content. May be empty.
     * @param string $domain The logical domain. MUST be non-empty.
     * @param string|null $context Disambiguation context. MUST be
     *        either `null` or a non-empty string.
     * @param array<value-of<PluralCategory>, string> $pluralForms
     *        Plural variants keyed by CLDR category. When non-empty,
     *        MUST contain the `OTHER` category.
     * @param array<non-empty-string, mixed> $metadata Format-specific
     *        metadata.
     *
     * @throws InvalidEntryException When any invariant is violated.
     */
    public function __construct(
        string $id,
        string $content = '',
        string $domain = 'messages',
        ?string $context = null,
        array $pluralForms = [],
        array $metadata = [],
    ) {
        if ($id === '') {
            throw new InvalidEntryException('Translation entry "id" must not be empty.');
        }

        if ($domain === '') {
            throw new InvalidEntryException('Translation entry "domain" must not be empty.');
        }

        if ($context === '') {
            throw new InvalidEntryException(
                'Translation entry "context" must be either null or a non-empty string.',
            );
        }

        if ($pluralForms !== [] && !array_key_exists(PluralCategory::OTHER->value, $pluralForms)) {
            throw new InvalidEntryException(
                'Translation entry plural forms must include the mandatory "other" category.',
            );
        }

        $this->id = $id;
        $this->content = $content;
        $this->domain = $domain;
        $this->context = $context;
        $this->pluralForms = $pluralForms;
        $this->metadata = $metadata;
        $this->key = self::buildKey($domain, $context, $id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    /**
     * @return array<value-of<PluralCategory>, string>
     */
    public function pluralForms(): array
    {
        return $this->pluralForms;
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isEmpty(): bool
    {
        if ($this->content !== '') {
            return false;
        }

        foreach ($this->pluralForms as $form) {
            if ($form !== '') {
                return false;
            }
        }

        return true;
    }

    public function withContent(string $content): self
    {
        return new self(
            id: $this->id,
            content: $content,
            domain: $this->domain,
            context: $this->context,
            pluralForms: $this->pluralForms,
            metadata: $this->metadata,
        );
    }

    /**
     * @param array<value-of<PluralCategory>, string> $pluralForms
     */
    public function withPluralForms(array $pluralForms): self
    {
        return new self(
            id: $this->id,
            content: $this->content,
            domain: $this->domain,
            context: $this->context,
            pluralForms: $pluralForms,
            metadata: $this->metadata,
        );
    }

    /**
     * @param array<non-empty-string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            id: $this->id,
            content: $this->content,
            domain: $this->domain,
            context: $this->context,
            pluralForms: $this->pluralForms,
            metadata: $metadata,
        );
    }

    /**
     * @param non-empty-string $domain
     * @param non-empty-string|null $context
     * @param non-empty-string $id
     *
     * @return non-empty-string
     */
    public static function buildKey(string $domain, ?string $context, string $id): string
    {
        $contextSegment = $context ?? self::NO_CONTEXT_SENTINEL;

        return $domain . self::KEY_SEPARATOR . $contextSegment . self::KEY_SEPARATOR . $id;
    }
}
