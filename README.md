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

Releases are three-part semver tags (`v1.0.0`, `v1.1.0`, …). Consumer bundles
pin the exact tag, never `@main`: an error pushed to this repository's `main`
would otherwise break CI in every bundle that depends on it at once.

```yaml
uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.8.1
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

Tool configuration is identical in every bundle. Bundle specifics live in the
files made for them:

- `deptrac.yaml` — the bundle's own layers;
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
  `php: >=8.4`, requires `symfony/yaml` when `src/` uses `YamlFileLoader`, and
  keeps its `autoload-dev` namespace under the package's own root
  namespace.
- every `symfony/*` entry in `require` uses `^6.4|^7.0|^8.0`, except
  `symfony/monolog-bundle` and the contracts packages (`symfony/contracts`,
  `symfony/*-contracts`): these follow their own major line, so they may use any
  constraint whose every alternative stays within one major (`^2.5|^3`, `~3.0`,
  `3.5.1`, `3.*`); `*`, `>=2`, `<4`, hyphen ranges (`2.5 - 9`), AND forms (`>=3.1 <4`),
  stability flags (`^3@dev`) and `dev-main` are rejected.
- `phpstan.dist.neon` declares `level: 9`, `level: 10` or `level: max`.
- `.github/workflows/checks.yml` calls `php-bundle.yml` pinned to a release
  tag (`@vX.Y.Z`, never `@main`).
- `psalm.xml` and `psalm-baseline.xml` are absent — the standard uses
  PHPStan only.

## Using the reusable workflow (once published)

A consumer bundle's own workflow becomes a thin wrapper:

```yaml
name: Checks

on:
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
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.8.1
    with:
      slug: msstc4symfony/healthcheck-bundle
    secrets:
      CODECOV_TOKEN: ${{ secrets.CODECOV_TOKEN }}
```

Inputs (all optional except `slug`):

| Input | Default | Purpose |
|-------|---------|---------|
| `slug` | — (required) | `owner/repo`, passed to Codecov uploads. |
| `php-versions` | `["8.4","8.5"]` | JSON array of PHP versions for the PHPUnit matrix. |
| `symfony-versions` | 6.4 / 7.4 / 8.x, coverage on 8.x | JSON array of `{version,label,codecov}` objects. |
| `extensions` | `mbstring, xml, ctype, iconv, intl` | PHP extensions installed for the bundle's test suite. |
| `ini-values` | `''` | `php.ini` overrides for jobs running bundle code, e.g. `apc.enable_cli=1`. |
| `run-deptrac` | `true` | Run the DEPTRAC layer-rules job. |
| `run-infection` | `true` | Run Infection mutation testing (push to `main` only). |
| `infection-min-msi` | `55` | Minimum Mutation Score Indicator, in percent; Infection fails the build below it. |
| `infection-min-covered-msi` | `55` | Minimum MSI over the mutants covered by tests, in percent. |
| `run-bc-check` | `true` | Run the Roave backward-compatibility check (blocking, see below). |
| `run-prefer-lowest` | `true` | Add the `--prefer-lowest` PHPUnit cell (see below). |
| `run-codecov` | `false` | Upload coverage and test results to Codecov; requires the `CODECOV_TOKEN` secret. |

Besides the PHPUnit matrix, the workflow runs `PHPUnit without optional libraries`: it installs
the published `composer.json` only, so `class_exists` / `interface_exists` guards and
self-skipping integration tests are verified in every bundle.

**Prefer-lowest cell.** One extra PHPUnit cell runs on the first entry of `php-versions` and the
first entry of `symfony-versions` (keep both lists ordered lowest first) and resolves the CI
manifest with `composer update --prefer-lowest --prefer-stable`, so the lower bounds a bundle
declares are actually tested. A bundle whose lower bounds do not work yet sets
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

A bundle brings itself into compliance by copying these files in as-is.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
```

## License

MIT.
