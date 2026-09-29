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
   `composer audit`, the PHPUnit matrix, an optional Roave BC check, and
   optional Infection mutation testing) for any bundle that calls it.

## Status: not yet published

The reusable workflow below is written to be consumed as:

```yaml
uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1
```

**That `v1` tag does not exist yet.** This repository currently lives only
on this machine, with no `git remote` and no tags — nothing has been
pushed to GitHub. The workflow file is written now so it is ready the
moment the repository is published and tagged; until then, no consumer
bundle can actually resolve the `@v1` reference. Do not wire a bundle's CI
to this workflow before publication.

Consumer bundles must pin a tag (`@v1`), never `@main`: an error pushed to
this repository's `main` would otherwise break CI in every bundle that
depends on it at once. Pinning a tag makes upgrading to a new standard
version a deliberate, per-bundle action instead of an involuntary one.

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

The rule set applies three different verification strengths, chosen per
file:

| Level | Files | Rule |
|-------|-------|------|
| Byte-for-byte match | `.php-cs-fixer.dist.php`, `phpstan-ci.neon`, `Makefile` | `ExactFileRule` |
| Key-value check | `rector.php`, `phpstan.dist.neon`, `phpunit.xml.dist`, `composer.json` | `ContainsRule`, `ComposerManifestRule` |
| Existence / absence | `deptrac.yaml`, `infection.json5`, `LICENSE`, `SECURITY.md`, `psalm.xml` | `FileExistsRule`, `FileAbsentRule` |

Files that legitimately carry per-bundle variation — each bundle keeps its
own Rector skip list and its own PHPStan `excludePaths` — are checked by
key values rather than byte-for-byte; requiring an exact match there would
forbid variation the standard is supposed to allow.

The rule set is assembled in `src/StandardDefinition::rules()` and checks,
among other things:

- `.php-cs-fixer.dist.php`, `phpstan-ci.neon`, and `Makefile` are
  byte-identical to the templates under `templates/`.
- `rector.php`, `phpstan.dist.neon`, and `phpunit.xml.dist` contain the
  required settings (e.g. `level: 9`, `phpVersion: 80400`,
  `failOnRisky="true"`), while still allowing bundle-specific additions
  (skip lists, exclude paths).
- `composer.json` declares no `version` field, lives under the
  `msstc4symfony` vendor, is licensed MIT, requires `php: >=8.4`, and
  keeps its `autoload-dev` namespace under the package's own root
  namespace.
- `composer-ci.json`, `phpstan-baseline.neon`, `deptrac.yaml`,
  `infection.json5`, `codecov.yml`, `LICENSE`, `SECURITY.md`, `README.md`,
  and `CLAUDE.md` are present.
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
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1
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
| `run-deptrac` | `true` | Run the DEPTRAC layer-rules job. |
| `run-infection` | `true` | Run Infection mutation testing (push to `main` only). |
| `run-bc-check` | `true` | Run the Roave backward-compatibility check. |

A consumer bundle does **not** need `bundle-standard` as a composer
dependency: the workflow's `standard-check` job checks out this
repository at `ref: v1` on its own and runs the verifier against the
bundle's checkout.

## Templates

`templates/` holds the reference files that `ExactFileRule` compares
against byte-for-byte:

- `templates/.php-cs-fixer.dist.php`
- `templates/phpstan-ci.neon`
- `templates/Makefile`

A bundle brings itself into compliance by copying these files in as-is.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
```

## License

MIT.
