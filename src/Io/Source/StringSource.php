<?php

declare(strict_types=1);

namespace G\Translator\Io\Source;

/**
 * `SourceInterface` implementation backed by an in-memory string.
 *
 * Useful for tests, ad-hoc parsing of translations embedded in
 * code, and consumption of payloads obtained through means the
 * package does not abstract (HTTP body, database column, ...).
 *
 * Identifier and extension are both opt-in: when omitted, the
 * identifier defaults to `string://<sha1>` (computed from the
 * payload for stability) and the extension to `null`. Setting
 * the extension explicitly is the standard way to drive
 * extension-based format detection on in-memory payloads.
 */
final readonly class StringSource implements SourceInterface
{
    /**
     * Length of the SHA-1 prefix used to build the default
     * identifier. 12 hex chars (48 bits) gives a low collision
     * probability for diagnostic use.
     */
    private const int IDENTIFIER_HASH_LENGTH = 12;

    /**
     * @var non-empty-string
     */
    private string $identifier;

    /**
     * @param string $content The raw payload. MAY be empty.
     * @param non-empty-string|null $identifier Optional explicit
     *        identifier (e.g., `"snippet-from-config"`). Defaults to
     *        a stable `string://<sha1-prefix>` derived from the
     *        content.
     * @param non-empty-string|null $extension Optional extension
     *        without leading dot (e.g., `"po"`, `"json"`). Drives
     *        extension-based auto-detection.
     */
    public function __construct(
        private string $content,
        ?string $identifier = null,
        private ?string $extension = null,
    ) {
        $this->identifier = $identifier ?? self::deriveIdentifier($content);
    }

    public function read(): string
    {
        return $this->content;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function extension(): ?string
    {
        if ($this->extension === null) {
            return null;
        }

        return strtolower($this->extension);
    }

    /**
     * @return non-empty-string
     */
    private static function deriveIdentifier(string $content): string
    {
        return 'string://' . substr(sha1($content), 0, self::IDENTIFIER_HASH_LENGTH);
    }
}
