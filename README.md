# Translator

A modern, zero-dependency, framework-agnostic translation library for PHP 8.3+.
Pipeline-driven: **Source → Parser → TranslationBag → Engine → Serializer**, with first-class AI bridges for batch translation via Claude, OpenAI, DeepL, or any custom provider.

[![Latest Version](https://img.shields.io/packagist/v/gadwinn/translator.svg)](https://packagist.org/packages/gadwinn/translator)
[![PHP Version](https://img.shields.io/packagist/php-v/gadwinn/translator.svg)](https://packagist.org/packages/gadwinn/translator)

---

## Why another translation library?

- **Zero runtime dependencies.** No PSR adapters, no Guzzle, no Symfony components. Only `php`, `ext-mbstring`, `ext-json`, `ext-curl`.
- **Pipeline architecture.** Each stage (read, parse, merge, resolve, serialize) is an isolated, swappable interface. No god-object.
- **Multi-format out of the box.** PO, XLIFF, YAML, JSON, PHP arrays, Angular JSON.
- **Stacking & merging.** Layer translations from multiple sources with explicit collision strategies. `THROW_ON_CONFLICT` by default — silent overrides require an explicit opt-in.
- **AI-native.** First-class contracts for batch translation through any AI provider, with built-in parallel HTTP execution.
- **Gettext-compatible procedural shell.** Opt-in `__()`, `__d()`, `__n()`, `__p()` and combinations, with Poedit-compatible argument order.

---

## Tech Stack

| Item | Value |
|---|---|
| PHP | `^8.3` |
| Extensions | `ext-mbstring`, `ext-json`, `ext-curl` |
| Production dependencies | **None** |
| License | MIT |
| Autoloading | PSR-4 (`G\Translator\` → `src/`) |
| Versioning | SemVer 2.0 |

---

## Installation

```bash
composer require gadwinn/translator
```

Optional: enable the Gettext-style procedural functions by adding the following to your application's `composer.json`:

```json
{
    "autoload": {
        "files": [
            "vendor/gadwinn/translator/src/Functions/translate.php"
        ]
    }
}
```

Then run `composer dump-autoload`.

---

## Usage

### 1. Load a translation file and translate

```php
use G\Translator\Io\Source\FileSource;
use G\Translator\Io\Detector\FormatDetector;
use G\Translator\Runtime\Translator;
use G\Translator\Runtime\BagRegistry;

$detector = new FormatDetector(); // ships with all built-in parsers/serializers

$source = new FileSource(__DIR__ . '/translations/fr_FR.po');
$bag    = $detector->parserFor($source)->parse($source, locale: 'fr-FR');

$registry = new BagRegistry();
$registry->register('fr-FR', $bag);

$translator = new Translator($registry);
$translator->setLocale('fr-FR');

echo $translator->trans('Hello %name%', params: ['name' => 'Alice']);
// → "Bonjour Alice"
```

### 2. Plural translation with automatic `{count}` injection

```php
echo $translator->plural('One apple', '{count} apples', 5);
// → "5 pommes"

// Override the injected count (e.g. formatted number)
echo $translator->plural(
    id:     'One apple',
    plural: '{count} apples',
    count:  5000,
    params: ['count' => number_format(5000, 0, ',', ' ')],
);
// → "5 000 pommes"
```

The form is chosen with the plural rules of the locale the translation was found in (an
English fallback entry follows the English rules). For an entry read from a PO file, the
`Plural-Forms` header of that file decides: its forms are numbered by its own expression, so
every language is handled correctly (Slovenian, Latvian...) without registering anything.
Other entries use the CLDR rules, or an expression registered with
`GettextExpressionPluralResolver::register()`.

### 3. Context (`msgctxt`) and domains

```php
// Disambiguation: "Post" as a noun vs. "Post" as a verb
echo $translator->trans('Post', context: 'noun');
echo $translator->trans('Post', context: 'verb');

// Domains separate logical scopes (admin, validators, messages, …)
echo $translator->trans('Welcome', domain: 'admin');
```

### 4. Multi-locale with automatic fallback

```php
use G\Translator\Runtime\LocaleFallback;

$fallback = new LocaleFallback();
$fallback->setPrimary('pl-PL');
$fallback->setChain(['en-GB', 'en']);   // explicit fallback list
$fallback->setAutoParent(true);          // also try pl, en-GB → en, etc.

// Resolution chain becomes: pl-PL → pl → en-GB → en
$translator = new Translator($registry, $fallback);
```

The locale set with `setLocale()` is kept by the translator. In a long-running worker
(FrankenPHP, RoadRunner...) where one translator serves many requests, set it again at the
start of each request (from the URL, a cookie or `Accept-Language`), or reset it after each
request (`gadwinn/framework`: `config/reset.php`); otherwise a request inherits the locale of
the previous one.

### 5. Strict mode for CI / development

```php
$translator->setStrict(true);

try {
    $translator->trans('non.existing.key');
} catch (\G\Translator\Exception\Runtime\MissingTranslationException $e) {
    // Loud failure: detect missing translations during testing
}
```

### 6. Stacking multiple sources

Useful for layered overrides (base translations + per-tenant overrides, or vendor defaults + user customizations):

```php
use G\Translator\Engine\StackEngine;
use G\Translator\Engine\StackedSource;
use G\Translator\Enum\MergeStrategy;

$engine = new StackEngine($detector);

$engine->add(new StackedSource(new FileSource('base/fr_FR.po'),   priority: 0));
$engine->add(new StackedSource(new FileSource('tenant/fr_FR.po'), priority: 10));
$engine->add(new StackedSource(new FileSource('user/fr_FR.po'),   priority: 20));

// Default = THROW_ON_CONFLICT: any collision raises MergeException.
// Pick REPLACE explicitly when overrides are intentional.
$mergedBag = $engine->resolve('fr-FR', MergeStrategy::REPLACE);
```

### 7. Serialize a bag to a different format

```php
use G\Translator\Enum\Format;

$json = $detector->serializerFor(Format::JSON)->serialize($mergedBag);
file_put_contents('public/i18n/fr-FR.json', $json);
```

### 8. Translate an entire bag with an AI provider

```php
use G\Translator\Bridge\Ai\BagTranslator;
use G\Translator\Bridge\Ai\Enum\TranslationQuality;
use G\Translator\Bridge\Ai\Provider\ClaudeProvider; // example concrete impl
use G\Translator\Http\CurlHttpClient;

$http     = new CurlHttpClient();
$provider = new ClaudeProvider($http, apiKey: getenv('ANTHROPIC_API_KEY'));

$orchestrator = new BagTranslator($provider);

$sourceBag = $detector->parserFor(new FileSource('en.po'))->parse(new FileSource('en.po'), 'en');
$targetBag = $orchestrator->translateBag(
    source:       $sourceBag,
    targetLocale: 'pl-PL',
    quality:      TranslationQuality::HIGH,
    metadata:     ['formality' => 'tu', 'preserve_html' => true],
);

$po = $detector->serializerFor(Format::PO)->serialize($targetBag);
file_put_contents('pl_PL.po', $po);
```

### 9. Per-request AI translation

```php
use G\Translator\Bridge\Ai\Request\TranslationRequest;

$result = $provider->translate(new TranslationRequest(
    sourceText:        'Welcome to the dashboard',
    sourceLocale:      'en',
    targetLocale:      'fr-FR',
    context:           'dashboard heading',
    quality:           TranslationQuality::STANDARD,
));

echo $result->translatedText;
// → "Bienvenue sur le tableau de bord"
```

### 10. Procedural API (Gettext-style)

After enabling the autoload entry described in the Installation section:

```php
use G\Translator\Functions\TranslatorRegistry;

// Bootstrap once
TranslatorRegistry::setDefault($translator);

// Anywhere in the app
echo __('Hello %name%',                 ['name' => 'Alice']);
echo __d('admin', 'Welcome %name%',     ['name' => 'Alice']);
echo __n('One apple', '{count} apples', 5);
echo __p('verb',   'Post');
echo __dp('admin', 'noun', 'Post');
echo __dn('admin', 'One user', '{count} users', 12);
echo __np('verb',  'One reply', '{count} replies', 3);
echo __dnp('admin', 'noun', 'One reply', '{count} replies', 3);
```

The procedural API supports named scopes for applications running multiple translators side by side:

```php
TranslatorRegistry::register('admin', $adminTranslator);
TranslatorRegistry::use('admin');
echo __('Edit user');   // uses $adminTranslator
TranslatorRegistry::use(null);
echo __('Edit user');   // uses the default again
```

---

## Placeholder syntaxes

Four families are supported simultaneously. **Do not mix families in a single translated string.**

| Syntax | Source style | Example | Escape |
|---|---|---|---|
| Named, percent | Gettext / Poedit | `%name%` | `%%` |
| Named, brace | ICU / Symfony 6+ | `{name}` | `{{` / `}}` |
| Positional | `sprintf` | `%s`, `%d` | `%%` |
| Indexed positional | `sprintf` | `%1$s`, `%2$d` | `%%` |

Placeholder values MUST be `scalar` or `Stringable`. Passing `null`, arrays, plain objects, or resources throws `InvalidPlaceholderValueException` — this is deliberate to surface upstream bugs.

---

## Merge strategies

```php
use G\Translator\Enum\MergeStrategy;

MergeStrategy::THROW_ON_CONFLICT;  // default — any collision raises MergeException
MergeStrategy::REPLACE;            // last-write-wins
MergeStrategy::KEEP_EXISTING;      // first-write-wins
```

Strategies are pluggable via `MergeStrategyHandlerInterface` + `MergeStrategyResolverInterface` for advanced scenarios.

---

## Exception hierarchy

Every exception thrown by the package implements `G\Translator\Exception\TranslatorExceptionInterface`. Catch this single type to handle any failure at the boundary of your code:

```php
use G\Translator\Exception\TranslatorExceptionInterface;

try {
    $translator->trans('greeting');
} catch (TranslatorExceptionInterface $e) {
    // Covers ParserException, SerializerException, MergeException,
    // MissingTranslationException, AiProviderException, etc.
}
```

Granular subclasses are available under `G\Translator\Exception\{Io,Bag,Engine,Runtime,Bridge,Configuration}\*` when specific recovery is needed.

---

## Architecture at a glance

```
Bridge → Runtime → Engine → Bag → Io → (Exception, Enum)
                                  ↑
                                 Http  (transverse utility)
                                 Functions  (opt-in procedural shell)
```

The dependency graph between layers is a strict DAG: downstream layers never reference upstream ones. This keeps every layer independently reasonable in scope and makes the codebase split-ready if you ever want to consume only the I/O layer or only the AI bridges.

---

## Public surface

Core contracts you will interact with most:

| Namespace | Contract |
|---|---|
| `G\Translator\Io\Source` | `SourceInterface`, `FileSource`, `StringSource`, `ResourceSource` |
| `G\Translator\Io\Parser` | `ParserInterface` |
| `G\Translator\Io\Serializer` | `SerializerInterface` |
| `G\Translator\Io\Detector` | `DetectorInterface` |
| `G\Translator\Bag` | `TranslationBagInterface`, `TranslationEntryInterface` |
| `G\Translator\Engine` | `StackEngineInterface`, `StackedSource` |
| `G\Translator\Engine\Strategy` | `MergeStrategyHandlerInterface`, `MergeStrategyResolverInterface` |
| `G\Translator\Runtime` | `TranslatorInterface`, `BagRegistryInterface`, `LocaleFallbackInterface` |
| `G\Translator\Runtime\Plural` | `PluralResolverInterface` |
| `G\Translator\Runtime\Placeholder` | `PlaceholderResolverInterface` |
| `G\Translator\Bridge\Ai` | `AiProviderInterface`, `BagTranslatorInterface` |
| `G\Translator\Bridge\Ai\Request` | `TranslationRequest` |
| `G\Translator\Bridge\Ai\Result` | `TranslationResult` |
| `G\Translator\Http` | `HttpClientInterface`, `HttpRequest`, `HttpResponse` |
| `G\Translator\Functions` | `TranslatorRegistry`, `__()` family |

Every interface is fully documented in its source file: invariants, expected behavior, and exhaustive `@throws` are part of the contract.
