# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pre-1.0 versions (`0.x.y`) MAY break public APIs without bumping major.
Each breaking change is explicitly listed in its section.

## [Unreleased]

### Added

#### Phase A — Foundation layer

- `G\Translator\Bag\TranslationEntry`: canonical immutable implementation
  of `TranslationEntryInterface`. `final readonly` value object with
  constructor-time invariant enforcement (`id` / `domain` non-empty,
  `context` either `null` or non-empty, mandatory `OTHER` category
  whenever any plural form is present) and `with*()` mutators returning
  fresh instances. Composite key encoded as
  `{domain}::{context|__none__}::{id}` via the public static
  `buildKey()` helper.
- `G\Translator\Bag\TranslationBag`: canonical mutable implementation of
  `TranslationBagInterface`. Backed by an associative array keyed on
  the composite key, preserves insertion order, refuses to merge bags
  whose locales mismatch, resolves collisions through `MergeStrategy`
  (`THROW_ON_CONFLICT` / `REPLACE` / `KEEP_EXISTING`).
- `G\Translator\Runtime\LocaleNormalizer`: pure static utility producing
  the canonical BCP-47 form of any accepted locale string
  (`pl_PL` / `pl-pl` / `PL-PL` → `pl-PL`) and computing the parent
  locale by trimming the rightmost subtag. Single source of truth for
  locale handling across the package.
- `G\Translator\Runtime\LocaleFallback`: canonical implementation of
  `LocaleFallbackInterface`. Maintains a primary locale, an explicit
  fallback chain (auto-normalized, deduped, with the primary stripped)
  and an opt-in automatic parent expansion. `chain()` returns the
  deduplicated resolution chain as a `non-empty-list<non-empty-string>`.
- `G\Translator\Runtime\BagRegistry`: canonical locale → bag registry
  with strict `get()` and lenient `find()` accessors, normalized locale
  keys, and replace-on-re-register semantics for hot-reload scenarios.
- `G\Translator\Exception\Bag\InvalidEntryException`: new bag-level
  exception thrown by `TranslationEntry` when its structural invariants
  are violated at construction time.

### Tooling

- Added `autoload-dev` PSR-4 mapping `G\Translator\Tests\` → `tests/`
  to `composer.json`. The production `require` section is unchanged.

### Tested

- 71 PHPUnit tests covering every Phase A class, including dedicated
  tests that document Phase A's design decisions (PHPDoc-as-validation,
  garbage-in-garbage-out semantics for runtime-unchecked invariants,
  composite-key disambiguation under `THROW_ON_CONFLICT`).
- PHPStan level `max` analysis is clean (zero errors, zero baseline).
