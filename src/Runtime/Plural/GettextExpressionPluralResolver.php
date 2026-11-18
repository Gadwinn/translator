<?php

declare(strict_types=1);

namespace G\Translator\Runtime\Plural;

use G\Translator\Enum\PluralCategory;
use G\Translator\Exception\Runtime\PluralResolutionException;
use G\Translator\Runtime\LocaleNormalizer;
use G\Translator\Runtime\Plural\Gettext\ExpressionNode;
use G\Translator\Runtime\Plural\Gettext\ExpressionParser;

/**
 * `PluralResolverInterface` implementation backed by Gettext-style
 * plural expressions parsed from PO `Plural-Forms` headers.
 *
 * The resolver maintains an internal locale → (AST, categories)
 * registry. Each expression is parsed *exactly once* via
 * `ExpressionParser` and the resulting AST is reused across all
 * subsequent `resolve()` calls for that locale.
 *
 * **Form-index to PluralCategory mapping.** A Gettext expression
 * returns a non-negative integer (the form index). The PO file
 * declares `nplurals=N`, the number of forms it carries. There is
 * no formal mapping from form index to CLDR category in the
 * Gettext spec; this resolver applies the following fixed
 * convention, mirrored by the `PoParser` so a single source of
 * truth is preserved end-to-end:
 *
 * | nplurals | Form 0 | Form 1 | Form 2 | Form 3 | Form 4 | Form 5 |
 * |---------:|:------:|:------:|:------:|:------:|:------:|:------:|
 * | 1        | OTHER  |        |        |        |        |        |
 * | 2        | ONE    | OTHER  |        |        |        |        |
 * | 3        | ONE    | FEW    | OTHER  |        |        |        |
 * | 4        | ONE    | FEW    | MANY   | OTHER  |        |        |
 * | 5        | ONE    | TWO    | FEW    | MANY   | OTHER  |        |
 * | 6        | ZERO   | ONE    | TWO    | FEW    | MANY   | OTHER  |
 *
 * Mixing bags parsed with this convention with bags hydrated
 * directly via CLDR rules is unsupported; the consumer SHOULD pick
 * one ingestion strategy per project.
 */
final class GettextExpressionPluralResolver implements PluralResolverInterface
{
    /**
     * @var int Highest `nplurals` value supported by the
     *      form-index → category mapping table.
     */
    public const int MAX_NPLURALS = 6;

    /**
     * Form-index → PluralCategory mapping keyed by `nplurals`.
     *
     * Documented in detail on the class docblock.
     *
     * @var array<int, list<PluralCategory>>
     */
    private const array NPLURALS_TO_CATEGORIES = [
        1 => [PluralCategory::OTHER],
        2 => [PluralCategory::ONE, PluralCategory::OTHER],
        3 => [PluralCategory::ONE, PluralCategory::FEW, PluralCategory::OTHER],
        4 => [PluralCategory::ONE, PluralCategory::FEW, PluralCategory::MANY, PluralCategory::OTHER],
        5 => [
            PluralCategory::ONE,
            PluralCategory::TWO,
            PluralCategory::FEW,
            PluralCategory::MANY,
            PluralCategory::OTHER,
        ],
        6 => [
            PluralCategory::ZERO,
            PluralCategory::ONE,
            PluralCategory::TWO,
            PluralCategory::FEW,
            PluralCategory::MANY,
            PluralCategory::OTHER,
        ],
    ];

    /**
     * @var array<non-empty-string, array{ast: ExpressionNode, categories: non-empty-list<PluralCategory>}>
     */
    private array $registry = [];

    /**
     * Parsed `Plural-Forms` header values, see {@see resolveHeader()}.
     *
     * @var array<string, array{ast: ExpressionNode, categories: non-empty-list<PluralCategory>}>
     */
    private array $headers = [];

    private const string NPLURALS_PATTERN = '/nplurals\s*=\s*(\d+)/';
    private const string PLURAL_PATTERN = '/(?<![a-z])plural\s*=\s*([^;]+)/';

    /**
     * Registers (or replaces) the plural expression for a locale.
     *
     * The expression is parsed and validated up front; malformed
     * inputs throw immediately so the failure surfaces near the
     * source (typically a PO parser) rather than at lookup time.
     *
     * @param non-empty-string $locale Locale string in any form
     *        accepted by `LocaleNormalizer`.
     * @param string $expression Raw expression as found after
     *        `plural=` in the PO `Plural-Forms` header.
     * @param int $nplurals Number of plural forms declared, between
     *        1 and `self::MAX_NPLURALS` inclusive.
     *
     * @throws PluralResolutionException When the expression is
     *         malformed or `$nplurals` is out of range.
     */
    public function register(string $locale, string $expression, int $nplurals): void
    {
        if (!isset(self::NPLURALS_TO_CATEGORIES[$nplurals])) {
            throw new PluralResolutionException(sprintf(
                'Unsupported nplurals value %d for locale "%s"; expected 1..%d.',
                $nplurals,
                $locale,
                self::MAX_NPLURALS,
            ));
        }

        $canonical = LocaleNormalizer::normalize($locale);
        $ast = ExpressionParser::parse($expression);

        $this->registry[$canonical] = [
            'ast' => $ast,
            'categories' => self::NPLURALS_TO_CATEGORIES[$nplurals],
        ];
    }

    /**
     * Indicates whether an expression has been registered for the
     * given locale.
     *
     * @param non-empty-string $locale
     */
    public function has(string $locale): bool
    {
        return isset($this->registry[LocaleNormalizer::normalize($locale)]);
    }

    public function resolve(int $count, string $locale): PluralCategory
    {
        $canonical = LocaleNormalizer::normalize($locale);
        if (!isset($this->registry[$canonical])) {
            throw new PluralResolutionException(sprintf(
                'No Gettext plural expression registered for locale "%s".',
                $canonical,
            ));
        }

        return $this->categoryAt($this->registry[$canonical], $count, 'locale "' . $canonical . '"');
    }

    /**
     * Resolves a count with the value of a PO `Plural-Forms` header
     * (`nplurals=N; plural=EXPR;`), whatever the locale.
     *
     * A PO parser stores `msgstr[i]` under the i-th category of the
     * same nplurals table, so evaluating the file's own expression
     * always selects the form the translator wrote, including for
     * languages whose order does not follow the CLDR one (Slovenian,
     * Latvian...). Parsed headers are cached by value.
     *
     * @throws PluralResolutionException When the header is malformed,
     *         nplurals is out of range or the expression yields an
     *         out-of-range form index.
     */
    public function resolveHeader(string $pluralForms, int $count): PluralCategory
    {
        if (!array_key_exists($pluralForms, $this->headers)) {
            $this->headers[$pluralForms] = $this->parseHeader($pluralForms);
        }

        return $this->categoryAt($this->headers[$pluralForms], $count, 'Plural-Forms "' . $pluralForms . '"');
    }

    /**
     * @return array{ast: ExpressionNode, categories: non-empty-list<PluralCategory>}
     *
     * @throws PluralResolutionException
     */
    private function parseHeader(string $pluralForms): array
    {
        if (
            preg_match(self::NPLURALS_PATTERN, $pluralForms, $nplurals) !== 1
            || preg_match(self::PLURAL_PATTERN, $pluralForms, $plural) !== 1
        ) {
            throw new PluralResolutionException(sprintf('Malformed Plural-Forms header "%s".', $pluralForms));
        }

        $count = (int) $nplurals[1];
        if (!isset(self::NPLURALS_TO_CATEGORIES[$count])) {
            throw new PluralResolutionException(sprintf(
                'Unsupported nplurals value %d in Plural-Forms header "%s"; expected 1..%d.',
                $count,
                $pluralForms,
                self::MAX_NPLURALS,
            ));
        }

        return [
            'ast' => ExpressionParser::parse($plural[1]),
            'categories' => self::NPLURALS_TO_CATEGORIES[$count],
        ];
    }

    /**
     * @param array{ast: ExpressionNode, categories: non-empty-list<PluralCategory>} $rule
     *
     * @throws PluralResolutionException When the form index is out of range.
     */
    private function categoryAt(array $rule, int $count, string $source): PluralCategory
    {
        $formIndex = $rule['ast']->evaluate($count);

        if ($formIndex < 0 || $formIndex >= count($rule['categories'])) {
            throw new PluralResolutionException(sprintf(
                'Gettext plural expression for %s returned out-of-range form index %d for count %d.',
                $source,
                $formIndex,
                $count,
            ));
        }

        return $rule['categories'][$formIndex];
    }
}
