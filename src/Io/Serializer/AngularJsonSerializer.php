<?php

declare(strict_types=1);

namespace G\Translator\Io\Serializer;

use G\Translator\Bag\TranslationBagInterface;
use G\Translator\Enum\Format;
use G\Translator\Exception\Io\SerializerException;
use JsonException;

/**
 * `SerializerInterface` implementation for ngx-translate JSON
 * output.
 *
 * Mirrors the parser's flatten convention: entry ids containing
 * `.` separators are un-flattened back into nested objects.
 * `app.title` becomes `{ "app": { "title": "..." } }`.
 *
 * If two entries share a prefix and one of them ends at a string
 * leaf while the other tries to descend further (e.g., `app` and
 * `app.title` both present), a `SerializerException` is thrown —
 * a value cannot be both a leaf and a container at the same path.
 *
 * An id with an empty segment (`a..b`, `.a`, `a.`) cannot be
 * expressed as nested keys and throws a `SerializerException` too.
 *
 * Limitations match `JsonSerializer`: no plurals, no context, no
 * metadata.
 */
final class AngularJsonSerializer implements SerializerInterface
{
    private const int ENCODE_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_FORCE_OBJECT
        | JSON_THROW_ON_ERROR;

    private const string KEY_SEPARATOR = '.';

    public function serialize(
        TranslationBagInterface $bag,
        ?string $domain = null,
    ): string {
        $tree = [];
        foreach ($bag->all($domain) as $entry) {
            $this->insertNested($tree, $entry->id(), $entry->content());
        }

        if ($tree === []) {
            return '{}';
        }

        try {
            return json_encode($tree, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw new SerializerException(
                'Failed to encode translation bag as Angular JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function format(): Format
    {
        return Format::ANGULAR_JSON;
    }

    /**
     * @param array<string, mixed> $tree
     * @param non-empty-string $id
     *
     * @param-out array<string, mixed> $tree
     */
    private function insertNested(array &$tree, string $id, string $value): void
    {
        $segments = explode(self::KEY_SEPARATOR, $id);
        if (in_array('', $segments, true)) {
            throw new SerializerException(sprintf(
                'Angular JSON serializer cannot nest id "%s": it has an empty segment.',
                $id,
            ));
        }
        $cursor = &$tree;
        $depth = count($segments);

        foreach ($segments as $index => $segment) {
            $isLast = $index === $depth - 1;

            if ($isLast) {
                if (isset($cursor[$segment]) && is_array($cursor[$segment])) {
                    throw new SerializerException(sprintf(
                        'Angular JSON serializer cannot place a string leaf at key "%s": '
                        . 'a nested object is already present at that path.',
                        $id,
                    ));
                }
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment])) {
                $cursor[$segment] = [];
            } elseif (!is_array($cursor[$segment])) {
                throw new SerializerException(sprintf(
                    'Angular JSON serializer cannot descend into key "%s": '
                    . 'a string leaf is already present at that path.',
                    $id,
                ));
            }

            /** @var array<string, mixed> $next */
            $next = &$cursor[$segment];
            $cursor = &$next;
        }
    }
}
