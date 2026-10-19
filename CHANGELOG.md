# Changelog

## 3.0.0 - 2026-10-19

- Added: `DeptracConfigRule` — `deptrac.yaml` declares the canonical layers of `templates/deptrac.yaml` with
  `^<bundle class>$` as `BundleRoot`, holds only `paths: [./src]`, `layers` and `ruleset`, and may only narrow the
  canonical ruleset (`exclude_files` and `skip_violations` are rejected).
- Added: `SourceLayoutRule` — `src/` holds only the layer directories, `Resources/` and one `<Name>Bundle.php`;
  `src/Domain/` only the Domain and DomainService directories.
- Added: `CompilerPassLocationRule` — every named or anonymous class that implements `CompilerPassInterface` or
  extends a Symfony `…Pass` lives in `src/DependencyInjection/CompilerPass/`.
- Added: `templates/deptrac.yaml`; root keys other than `deptrac` and transitive `+Layer` dependencies are rejected;
  the key order inside a layer is not significant.
- Added: `phpstan.dist.neon` (php82, php84) sets `checkMissingOverrideMethodAttribute: true` — `#[\Override]` on every
  overriding or implementing method.
- Added: `SourceLayoutRule` also rejects class declarations under `src/Resources/` and dot directories in `src/`.
- Changed: `bin/verify-standard.php` exits with code 2 and "The standard cannot be loaded: …" when a template is broken
  (checked even when the bundle lacks `deptrac.yaml`), and "The bundle cannot be read: …" for an unreadable directory or file.
- Changed: the `php74` profile is exempt from the three layout rules.
- Changed: `symfony/yaml` moved from `require-dev` to `require`.
- Changed: the reference bundle of the test suite is skeleton-bundle.
- Upgrading: copy `templates/deptrac.yaml` and `templates/phpstan.dist.neon`, move classes into the canonical layers, add
  `#[\Override]` where PHPStan reports `method.missingOverride`.

## 2.0.1 - 2026-10-14

- Fixed: `symfony/polyfill` and `symfony/polyfill-*` in `require` is checked like the contracts packages (one major per alternative,
  e.g. `^1.31`) instead of being held to the lockstep `^7.4|^8.0`.

## 2.0.0 - 2026-10-14

- Changed: the default runtime profile is `php82` — `require.php` must be `>=8.2`; the shared templates keep the
  code on PHP 8.2 (`phpstan.dist.neon` `phpVersion: {min: 80200, max: 80599}`, `rector.php`
  `withPhpVersion(PHP_82)` + `withPhpSets(php82: true)`, `.php-cs-fixer.dist.php` `new_expression_parentheses`).
- Changed: `php-versions` defaults to `["8.2","8.3","8.4","8.5"]`; the "minimal" job runs on PHP 8.2 by default.
- Added: the `php84` profile with its own templates (the previous shared ones).
- Added: the `matrix` job builds the PHPUnit cells and skips those below the package's runtime profile or below
  a `symfony-versions` entry's new `php` field (Symfony 8 → `"php":"8.4"`); the prefer-lowest cell is the first
  remaining pair; no remaining pair or an unknown runtime profile fails the job; `run-codecov` with no cell to
  upload from warns. The cells drop Infection, which needs PHP 8.3.
- Changed: Codecov uploads from the `PRIMARY_PHP` (8.4) cell instead of a hard-coded `8.4`.

### Upgrading from 1.x

- A package without a declared profile requires `php >=8.2` and takes the new `rector.php`, `phpstan.dist.neon`
  and `.php-cs-fixer.dist.php`; its code must parse on PHP 8.2 (no typed class constants, no `new X()->m()`);
  `composer-ci.json` requires `friendsofphp/php-cs-fixer` `^3.76` (`new_expression_parentheses`).
- PHP 8.3/8.4 functions need the matching `symfony/polyfill-php8x` (in `require` when `src/` calls them); only the
  PHP 8.2/8.3 PHPUnit cells catch a missing one. Rector no longer adds `#[Override]` to new code.
- A package that needs PHP 8.4 declares `"extra": {"bundle-standard": {"runtime": "php84"}}` and takes the
  templates from `templates/php84/`.
- A package passing its own `symfony-versions` adds each entry's lowest PHP: `"php":"8.2"` to Symfony 7.4,
  `"php":"8.4"` to Symfony 8.

## 1.1.0 - 2026-10-05

- Optional Elasticsearch integration job (`elasticsearch` input).

## 1.0.0 - 2026-10-04

- First release: the verifier and the reusable workflow.
