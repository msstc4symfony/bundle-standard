# Bundle Standard

Shared quality standard for `msstc4symfony` Symfony bundles.

This repository is the single source of truth for what "compliant with the
`msstc4symfony` standard" means for a bundle: which files must exist,
which must be byte-identical to a reference template, which must contain
specific settings, and which must not exist at all. It ships two things:

1. **A CLI verifier** (`bin/verify-standard.php`) that checks a bundle
   directory against the standard and reports every violation.
2. **A reusable GitHub Actions workflow**
   (`.github/workflows/php-bundle.yml`) that runs the verifier plus the
   full quality gate (lint, PHPStan, PHP-CS-Fixer, Rector, DEPTRAC,
   `composer audit`, the PHPUnit matrix with a `--prefer-lowest` cell, the
   Roave BC check and Infection mutation testing with a minimum MSI) for any
   bundle that calls it. Every job is blocking.

## Versioning

Releases are three-part semver tags (`v1.0.0`, `v2.0.0`, …). Consumer bundles
pin the exact tag, never `@main`: an error pushed to this repository's `main`
would otherwise break CI in every bundle that depends on it at once.

```yaml
uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v3.3.0
```

GitHub resolves `@…` to a literal ref, not a version range, so upgrading the
standard is a deliberate per-bundle bump of this tag.

The workflow's `standard-check` job checks out this repository at a hard-coded
`ref:`. That value must equal the release tag: when releasing, bump it in
`.github/workflows/php-bundle.yml` in the same commit that gets tagged.
`ReusableWorkflowTest` fails if the workflow and this README disagree.

## Verifying a bundle

```sh
php bin/verify-standard.php <path-to-bundle>
```

Exit codes:

| Code | Meaning |
|------|---------|
| `0`  | The bundle complies with the standard. |
| `1`  | The bundle violates the standard; every violation is printed to `STDERR` as `<file>: <message>`. |
| `2`  | Usage error — the argument is missing or is not a directory. |

The rule set applies three verification strengths, chosen per file:

| Level | Files | Rule |
|-------|-------|------|
| Byte-for-byte match | `.php-cs-fixer.dist.php`, `phpstan-ci.neon`, `rector.php`, `Makefile`, `phpunit.xml.dist`, `infection.json5`, `codecov.yml`, `.gitignore` | `ExactFileRule` |
| Byte-for-byte match except the `level:` line, which must be `9`, `10` or `max` | `phpstan.dist.neon` | `PhpstanConfigRule` |
| Key-value check | `.github/workflows/checks.yml`, `composer.json` | `ContainsRule`, `ComposerManifestRule` |
| Existence / absence | `composer-ci.json`, `phpstan-baseline.neon`, `deptrac.yaml`, `LICENSE`, `SECURITY.md`, `README.md`, `CLAUDE.md`, `psalm.xml` | `FileExistsRule`, `FileAbsentRule` |
| Layout (not under `php74`) | `deptrac.yaml` (canonical layers, ruleset may only be narrowed, no `exclude_files`/`skip_violations`), `src/` (only the layer directories and `<Name>Bundle.php`; a [library](#package-kinds) has no bundle class), compiler passes only in `src/DependencyInjection/CompilerPass/` | `DeptracConfigRule`, `SourceLayoutRule`, `CompilerPassLocationRule` |

Tool configuration is identical in every bundle. Bundle specifics live in the
files made for them:

- `deptrac.yaml` — a narrower ruleset than the canonical one (the layers themselves are fixed);
- `phpstan-baseline.neon` — accepted findings, each with a reason (e.g. a test fixture that
  deliberately extends a class from a package that is not installed);
- `composer.json` / `composer-ci.json` — dependencies and optional libraries;
- `.github/workflows/checks.yml` — workflow inputs (`slug`, PHP `extensions`, `ini-values`).

Every bundle therefore uses the same layout: `src/`, `tests/Unit/` and `tests/Integration/`
(PHPUnit suites `unit` and `integration`), with `autoload-dev` mapping `<Root>\Test\` to
`tests/`.

The rule set is assembled in `src/StandardDefinition::rules()` and also checks:

- `composer.json` declares no `version` field, lives under the
  `msstc4symfony` vendor, is licensed MIT, lists
  `Maxim Shamaev <maxim.shamaev@gmail.com>` among its `authors`, requires
  `php: >=8.2` (`>=8.4` under the `php84` runtime profile, `>=7.4` under `php74`), requires `symfony/yaml` when `src/` uses `YamlFileLoader`, and
  keeps its `autoload-dev` namespace under the package's own root
  namespace.
- every `symfony/*` entry in `require` uses `^7.4|^8.0`, except
  `symfony/monolog-bundle`, the contracts packages (`symfony/contracts`,
  `symfony/*-contracts`) and the polyfills (`symfony/polyfill`,
  `symfony/polyfill-*`): these follow their own major line, so they may use any
  constraint whose every alternative stays within one major (`^2.5|^3`, `~3.0`,
  `3.5.1`, `3.*`); `*`, `>=2`, `<4`, hyphen ranges (`2.5 - 9`), AND forms (`>=3.1 <4`),
  stability flags (`^3@dev`) and `dev-main` are rejected.
- `phpstan.dist.neon` declares `level: 9`, `level: 10` or `level: max`.
- `.github/workflows/checks.yml` calls `php-bundle.yml` pinned to a release
  tag (`@vX.Y.Z`, never `@main`).
- `psalm.xml` and `psalm-baseline.xml` are absent — the standard uses
  PHPStan only.
- the hex layout (every profile except `php74`, whose packages run inside other tools):
  `deptrac.yaml` declares exactly the layers of `templates/deptrac.yaml` — `Domain`, `DomainService`,
  `Application`, `Infrastructure`, `Presentation`, `Framework`, `DependencyInjection`, `BundleRoot` — with the
  `BundleRoot` value set to `^<bundle class>$` (derived from the one `autoload.psr-4` prefix of `src/` and
  `src/<Name>Bundle.php`, backslashes doubled); `deptrac` holds only `paths: [./src]`, `layers` and `ruleset`; every ruleset entry is a subset of the
  canonical one. `src/` holds only those layer directories, `Resources/` and exactly one `<Name>Bundle.php`;
  `src/Domain/` only the Domain and DomainService directories; no PHP file under `src/Resources/` declares a class.
  `deptrac.yaml` has no root key besides `deptrac` (`imports` or `services` would merge in rules unchecked) and no
  transitive `+Layer` dependencies. Every class — named or anonymous — that directly implements
  `CompilerPassInterface` or extends a Symfony class ending in `Pass` (`AbstractRecursivePass`,
  `RegisterListenersPass`, …) lives under `src/DependencyInjection/CompilerPass/`. The check reads tokens and never
  loads classes, so a child of the bundle's own abstract pass is not recognised — its base class is.

## Starting a new bundle

Create the repository from the [`skeleton-bundle`](https://github.com/msstc4symfony/skeleton-bundle)
template and run `php bin/init <Name> "<description>"`: the result already complies with this standard
and passes `php-bundle.yml`.
Its `deptrac.yaml` is `templates/deptrac.yaml` with the full canonical ruleset; narrow it as the bundle's
layers settle.

## Runtime profiles

A package declares the PHP it must run on in `composer.json`:

```json
"extra": {"bundle-standard": {"runtime": "php84"}}
```

| Profile | Default | `require.php` | `symfony/*` constraint | Templates |
|---------|---------|---------------|------------------------|-----------|
| `php82` | yes | `>=8.2` | `^7.4\|^8.0` | `templates/` |
| `php84` | no | `>=8.4` | `^7.4\|^8.0` | `templates/php84/` where present, else `templates/` |
| `php74` | no | `>=7.4` | `^5.4\|^6.4\|^7.0\|^8.0` | `templates/php74/` where present, else `templates/` |

The shared templates keep the code on PHP 8.2: `phpstan.dist.neon` analyses for PHP 8.2–8.5
(`phpVersion: {min: 80200, max: 80599}`), `rector.php` applies the PHP 8.2 sets under `withPhpVersion(PHP_82)`,
and `.php-cs-fixer.dist.php` keeps the parentheses around `new` expressions (the `new_expression_parentheses`
rule needs `friendsofphp/php-cs-fixer` `^3.76` in `composer-ci.json`). `php84` is for a package that
needs PHP 8.4: its templates are the shared ones targeting 8.4 (`phpVersion: 80400`, `withPhpSets(php84: true)`,
no `new_expression_parentheses`).

The tools run on PHP 8.4 and catch only part of what PHP 8.2 rejects: PHPStan flags typed class constants,
`php -l` in the "minimal" job (PHP 8.2) catches the rest of the syntax. Nothing but the PHP 8.2/8.3 PHPUnit
cells catches a PHP 8.3/8.4 function (`array_any`, `json_validate`, …): use one only with the matching
`symfony/polyfill-php8x` in `require` (`require-dev` when only tests call it). Under `withPhpVersion(PHP_82)`
Rector no longer adds `#[Override]` (a PHP 8.3 rule): packages that keep the attribute add it by hand.

`php74` exists for packages that run inside another tool on older PHP — the Symfony bridge of the DTO
generator runs in the generator's runtime, which supports PHP 7.4. Its templates differ only where PHP 7.4,
PHPUnit 9.6 or Symfony 5.4 need it: `phpstan.dist.neon` (`phpVersion: 70400`), `.php-cs-fixer.dist.php` (no
trailing comma after the last parameter), `rector.php` (`withPhpVersion(PHP_74)`, no attribute sets, Symfony
floor 5.4) and `phpunit.xml.dist` (PHPUnit 9.6 schema). Any other `runtime` value is a violation.

The workflow reads the profile itself: the "PHPUnit without optional libraries" job installs `composer.json`
alone on the profile's PHP (8.2, 8.4 or 7.4) and, below PHP 8.4, lints every source one file at a time. Every other job
installs `composer-ci.json` on PHP 8.4 or the matrix versions, so a `php74` package keeps only what installs on
PHP 7.4 in the `require-dev` of `composer.json` (PHPUnit 9.6) and the tools in `composer-ci.json`.

Two consequences to know:

- PHPUnit 9.6 cannot tell the package's deprecations from its dependencies', so `convertDeprecationsToExceptions`
  fails the suite on any deprecation a test triggers — on a new PHP that may be a dependency's.
- A package without Symfony in `require` is a library: declare it as one (see [Package kinds](#package-kinds)) and
  pass the single `none` entry shown there. A bundle that wants a single cell per PHP still needs a real Symfony
  version in its one entry (`symfony-versions: '[{"version":"7.4.*","label":"7.4","codecov":false}]'`): the cell
  pins and asserts it.

## Package kinds

A package that is not a Symfony bundle — plain PHP code that other packages use — declares itself a library:

```json
"extra": {"bundle-standard": {"kind": "library"}}
```

`kind` is `bundle` when absent; any other value than `bundle` or `library` is a violation, and the rest of the
package is then checked as a bundle. For a library:

- `src/` holds only `Domain/`, `Application/`, `Infrastructure/`, `Presentation/`, `Framework/` and `Resources/`.
  A `src/<Name>Bundle.php` is a violation (declare `kind: "bundle"` or remove it), and so is
  `src/DependencyInjection/`: without a bundle class nothing loads a container extension. For the same reason every
  compiler pass is a violation, wherever it lives. The `src/Domain/`,
  dot-directory and `src/Resources/` checks are the same as for a bundle.
- `deptrac.yaml` is a copy of `templates/deptrac-library.yaml`: `templates/deptrac.yaml` without the
  `DependencyInjection` and `BundleRoot` layers and their ruleset entries, so there is no placeholder to replace.
- `composer.json` needs neither `extra.symfony.require` nor `conflict.symfony/symfony = "*"`: a library may not
  depend on Symfony at all. Every other `composer.json` check applies, including the constraint of any
  `symfony/*` package it does require.

Kind and runtime profile are independent: a `php74` library skips the layout rules like any `php74` package.

In the PHPUnit cells, the workflow pins a library only to the Symfony components its `composer-ci.json` lists
(`require` and `require-dev`, the same selection as for a bundle) and skips the kernel core a bundle gets
(`framework-bundle`, `console`, `http-kernel`, `dependency-injection`, `config`). The cell then asserts the
installed version of the first pinned package instead of `symfony/http-kernel`. A library without any Symfony
component pins and asserts nothing, so every `symfony-versions` entry would produce an identical cell: pass a single
entry without `php`, e.g. `symfony-versions: '[{"version":"none","label":"none","codecov":true}]'`. Nothing is pinned
to `none`, but the value still shows up in two places. The version is part of the check name
(`PHPUnit (PHP 8.4, Symfony none)`), and required status checks follow that name. The label is part of the
`vendor` cache key. If the library later adds a Symfony component, the cell's `composer require symfony/…:none` fails
with "Could not parse version constraint none": at that point set real versions.

## Using the reusable workflow (once published)

A consumer bundle's own workflow becomes a thin wrapper:

```yaml
name: Checks

on:
  workflow_dispatch:
  pull_request:
    paths-ignore: ['**/*.md', LICENSE]
  push:
    branches: [main]
    paths-ignore: ['**/*.md', LICENSE]

concurrency:
  group: ci-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

jobs:
  standard:
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v3.3.0
    with:
      slug: msstc4symfony/healthcheck-bundle
    secrets:
      CODECOV_TOKEN: ${{ secrets.CODECOV_TOKEN }}
```

`workflow_dispatch` lets you run the checks by hand (`gh workflow run checks.yml --ref main`): a push that only touches Markdown, or a force-push, does not trigger them.


Inputs (all optional except `slug`):

| Input | Default | Purpose |
|-------|---------|---------|
| `slug` | — (required) | `owner/repo`, passed to Codecov uploads. |
| `php-versions` | `["8.2","8.3","8.4","8.5"]` | JSON array of PHP versions for the PHPUnit matrix, lowest first. |
| `symfony-versions` | 7.4 (from PHP 8.2) / 8.x (from PHP 8.4), coverage on 8.x | JSON array of `{version,label,codecov,php?}` objects; `php` is the lowest PHP that Symfony installs on (7.4: 8.2, 8: 8.4). |
| `extensions` | `mbstring, xml, ctype, iconv, intl` | PHP extensions installed for the bundle's test suite. |
| `ini-values` | `''` | `php.ini` overrides for jobs running bundle code, e.g. `apc.enable_cli=1`. |
| `run-deptrac` | `true` | Run the DEPTRAC layer-rules job. |
| `run-infection` | `true` | Run Infection mutation testing (push to `main` only). |
| `infection-min-msi` | `55` | Minimum Mutation Score Indicator, in percent; Infection fails the build below it. |
| `infection-min-covered-msi` | `55` | Minimum MSI over the mutants covered by tests, in percent. |
| `run-bc-check` | `true` | Run the Roave backward-compatibility check (blocking, see below). |
| `run-prefer-lowest` | `true` | Add the `--prefer-lowest` PHPUnit cell (see below). |
| `run-phpstan-per-symfony` | `true` | Run PHPStan again in the PHP 8.4 PHPUnit cell of each `symfony-versions` entry (see below). |
| `elasticsearch` | `[]` | JSON array of `{elastica,image}`; adds an Elasticsearch integration job per entry (see below). `[]` or an empty string skips it. |
| `run-codecov` | `false` | Upload coverage and test results to Codecov; requires the `CODECOV_TOKEN` secret. |

Besides the PHPUnit matrix, the workflow runs `PHPUnit without optional libraries`: it installs
the published `composer.json` only, so `class_exists` / `interface_exists` guards and
self-skipping integration tests are verified in every bundle.

**Elasticsearch integration.** A bundle with live-cluster tests (PHPUnit group `elasticsearch`) sets
the `elasticsearch` input to a JSON list; one job runs per entry on the primary PHP, starts the
entry's `image` as a service on port 9200 (single node, security off) and installs the entry's
`elastica` constraint over the CI manifest before running `vendor/bin/phpunit --group elasticsearch`
with `ELASTICSEARCH_URL=http://localhost:9200`. The tests must skip themselves when that variable is
unset. With the default `[]` (or an empty string) the job is skipped and nothing else changes.

```yaml
with:
  slug: msstc4symfony/metrics-bundle
  elasticsearch: '[{"elastica":"^7.3","image":"docker.elastic.co/elasticsearch/elasticsearch:7.17.29"},{"elastica":"^8.0","image":"docker.elastic.co/elasticsearch/elasticsearch:8.19.22"}]'
```

**PHPUnit matrix.** The `matrix` job pairs every `php-versions` entry with every `symfony-versions` entry
and drops the pairs below the package's runtime profile (a `php84` package gets no PHP 8.2/8.3 cell) or below
the Symfony entry's `php`: set it to the lowest PHP that Symfony line installs on (7.4 → `"8.2"`, 8 → `"8.4"`),
so the default matrix has no Symfony 8 cell on PHP 8.2 or 8.3. Give `php-versions` as strings: a JSON number
loses trailing zeros (`8.10` reads as `8.1`). When no pair remains the job fails with an error instead of
starting an empty matrix; with `run-codecov` on and no cell on PHP 8.4 with a `codecov` Symfony entry, it warns
that nothing will be uploaded. Each cell removes Roave BC check, DEPTRAC and Infection from the CI manifest
before resolving: they run in their own jobs on PHP 8.4, and Infection 0.32 does not install on PHP 8.2.

**PHPStan per Symfony version.** The `PHPStan` job analyses the install of the `setup` job, which is cached by
the CI manifest: it sees one Symfony version, and the dependency releases of the day the cache was filled. With
`run-phpstan-per-symfony` on, the PHP 8.4 cell of each `symfony-versions` entry also runs
`phpstan analyse -c phpstan-ci.neon` against the dependencies it has just resolved, after the PHPUnit run, so
API that differs between Symfony versions and newer dependency releases fail the build in that cell. A finding
for one version only goes into `phpstan-baseline.neon`: `phpstan-ci.neon` does not report its pattern as
unmatched on the others. The cell has removed Roave BC check, DEPTRAC and Infection before resolving, so a
reference to their classes is a `class.notFound` here only; it goes into the baseline too. The `matrix` job
warns for an entry with no PHP 8.4 cell, which PHPStan skips.

**Prefer-lowest cell.** One extra PHPUnit cell runs on the first remaining pair of the matrix — the first entry
of `php-versions` with the first entry of `symfony-versions` it can install (keep both lists ordered lowest
first) — and resolves the CI manifest with `composer update --prefer-lowest --prefer-stable`, so the lower
bounds a bundle declares are actually tested. A bundle whose lower bounds do not work yet sets
`run-prefer-lowest: false` until it raises them.

**Infection threshold.** Infection fails the build when the MSI or the covered-code MSI drops below
`infection-min-msi` / `infection-min-covered-msi`. The defaults (55 / 55) sit a few points below the
weakest bundle when they were introduced (metrics-bundle, 59 %); a bundle with a better score should
raise its own inputs rather than wait for the default to move. A bundle without mutants passes.
Infection runs on pushes to `main` only, so a pull request that lowers the score turns `main` red
after the merge; run `make infection` locally before merging changes to `src/`.

**BC check and new majors.** The Roave check compares `HEAD` with the latest stable tag (pre-release
tags are not a baseline) and fails the build on any backward-incompatible change. It is skipped, with
a notice, only while a new major is being prepared:

- the pushed branch, or the target branch of a pull request, is named exactly after a major above the
  latest stable tag's: `2.x`, `2.0`, `release/2.0` or `v2.0` while that tag is `v1.*`
  (`2.x-feature` or `3.5-hotfix` do not count);
- the nearest tag is a pre-release of such a major (`v2.0.0-rc1` after `v1.3.0`).

So a 2.0 release-candidate branch (`2.x`) runs without the check until `v2.0.0` is tagged. To merge
that branch back into `main`, tag the first pre-release (`v2.0.0-rc1`) on it before opening the pull
request; without that tag the merge is compared with `v1.*` and fails. A pre-release of a minor
(`v1.4.0-beta1`) does not switch the check off. A pushed release tag is compared with the stable tag before it. For an intentional major prepared directly on
`main`, set `run-bc-check: false` in that bundle's `checks.yml` for the release and restore it right
after the tag.

A consumer bundle does **not** need `bundle-standard` as a composer
dependency: the workflow's `standard-check` job checks out this
repository at the release tag on its own and runs the verifier against the
bundle's checkout.

## Templates

`templates/` holds the reference files that `ExactFileRule` (and, level line aside,
`PhpstanConfigRule`) compares against byte-for-byte: `.php-cs-fixer.dist.php`, `phpstan-ci.neon`, `phpstan.dist.neon`,
`rector.php`, `Makefile`, `phpunit.xml.dist`, `infection.json5`, `codecov.yml` and `gitignore`
(copied to the bundle as `.gitignore`; stored without the dot so it does not apply here).

`templates/deptrac.yaml` is copied too, with its `'%BUNDLE_CLASS_PATTERN%'` replaced by the anchored bundle class
(`^Msstc4Symfony\\FooBundle\\FooBundle$` as a plain or single-quoted scalar — double quotes would collapse the
backslashes; the anchors are needed because deptrac applies the value as a regex without them); `DeptracConfigRule` compares its layers and ruleset after
parsing, so comments and the key order inside a layer may differ. A library copies `templates/deptrac-library.yaml`
instead, as-is (see [Package kinds](#package-kinds)). `phpstan.dist.neon` (php82, php84) sets
`checkMissingOverrideMethodAttribute: true`: every method that overrides a parent or implements an interface
method carries `#[\Override]`. Rector cannot add it under `withPhpVersion(PHP_82)` (the rule needs PHP 8.3) and
skips interface methods, so PHPStan enforces it; on PHP 8.2 the attribute class does not exist, which is harmless
because attributes are not instantiated.

A bundle brings itself into compliance by copying these files in as-is.

## Shared service decoration

Family bundles that decorate the same Symfony service (`http_client.transport`) register their priority in
[`doc/decoration-priorities.md`](doc/decoration-priorities.md) and must not depend on each other's order.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
```

## License

MIT.
