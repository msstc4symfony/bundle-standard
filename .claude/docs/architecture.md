# Архитектура

## Что это и как запускается

`bundle-standard` — верификатор общего стандарта качества для семейства
Symfony-бандлов `msstc4symfony`. Точка входа —
`bin/verify-standard.php <path-to-bundle>`:

- `exit(0)` — бандл соответствует стандарту.
- `exit(1)` — есть нарушения; каждое печатается в STDERR как
  `<file>: <message>`.
- `exit(2)` — ошибка использования (аргумент отсутствует или не является
  директорией).

CLI создаёт `Verifier` со списком правил из
`StandardDefinition::rules($templatesDir)` и вызывает `verify($bundlePath)`.

## Контракт `RuleInterface` и DTO `Violation`

```php
interface RuleInterface
{
    /** @return list<Violation> */
    public function check(string $bundlePath): array;
}
```

`Violation` (`src/Violation.php`) — `final readonly class` с полями
`file` и `message` (оба `non-empty-string`). Конструктор бросает
`InvalidArgumentException`, если любое из полей — пустая строка; нарушение
без описания или без указания файла не имеет смысла. `format()` возвращает
`"<file>: <message>"` — ровно то, что печатает CLI.

`Verifier::verify()` просто прогоняет `bundlePath` через все правила и
конкатенирует их `list<Violation>` в один список.

## Правила (`src/Rule/`)

- `ExactFileRule` — файл в бандле должен быть побайтово идентичен файлу
  из `templates/`.
- `ContainsRule` — файл должен существовать и содержать каждую строку из
  переданного списка обязательных подстрок (остальное содержимое файла не
  проверяется).
- `FileExistsRule` — файл должен существовать (причина обязательности
  передаётся текстом и попадает в сообщение о нарушении).
- `FileAbsentRule` — файл должен отсутствовать.
- `PhpstanConfigRule` — `phpstan.dist.neon` побайтово равен
  шаблону, кроме первой строки `level:` (хвостовой `# comment` допустим);
  уровень — строго один из `9`, `10`, `max` (уровней выше 10 в PHPStan 2
  нет, `09`/`11` отклоняются). Шаблон остаётся на `level: 9`, пока все шесть
  бандлов не чисты на 10 (на 2026-10-02 UTC чисты только profiling-bundle
  и metrics-bridge-profiling).
- `ComposerManifestRule` — точечные проверки `composer.json`: нет поля
  `version`, вендор `msstc4symfony`, лицензия `MIT`, `require.php`
  строго по профилю (`>=8.2` по умолчанию), `autoload-dev.psr-4` не содержит чужих неймспейсов
  (защита от copy-paste, когда в новый бандл случайно попадает
  тестовый неймспейс другого бандла). Каждый `symfony/*` в `require` —
  `^7.4|^8.0` (php82/php84; нижняя граница Symfony 7.4), кроме `symfony/monolog-bundle`, contracts
  (`symfony/contracts`, `symfony/*-contracts`) и полифиллов (`symfony/polyfill`,
  `symfony/polyfill-*`, с v2.0.1): у них своя линейка мажоров,
  поэтому допустим любой constraint, где каждая альтернатива привязана к
  одному мажору (`^3`, `~3.1`, `3.5.1`, `3.*`, допускается префикс `v`;
  дефисные диапазоны, `<`/`>`, `*`, `dev-*` — нарушение).
- `MissingFileViolationTrait` — общий guard «файл отсутствует»,
  переиспользуется `ExactFileRule` и `ContainsRule` перед их
  специфичной проверкой (сначала убеждаемся, что есть что сравнивать).

## Правила раскладки (v3, спек этапа B)

Не применяются к профилю `php74` (`StandardDefinition`, `$layoutRules`).

- `DeptracConfigRule` — разбирает `deptrac.yaml` (`symfony/yaml`, поэтому он в `require`). Шаблон разбирается
  первым при каждом вызове (битый → `StandardTemplateException`, даже если у пакета нет `deptrac.yaml`). Корень —
  только `deptrac` (deptrac грузит файл через Symfony DI: `imports`/`services` подмешали бы непроверенные правила).
  `layers` сравниваются послойно после рекурсивной сортировки ключей map'ов (порядок слоёв и элементов списков
  важен, порядок ключей и комментарии — нет); сообщение называет первый отличающийся слой. Плейсхолдер
  `'%BUNDLE_CLASS_PATTERN%'` → `^<FQCN>$` с удвоенными `\\`: deptrac оборачивает значение `classLike` в `/…/i` и
  сопоставляет без якорей (`AbstractTypeCollector::getPattern`, `ClassLikeToken::match`), без `^$` слой поймал бы
  любой FQCN, содержащий имя бандла. FQCN — `BundleClass::of()`: один `src/<Name>Bundle.php`
  (`BundleClass::FILE_PATTERN`, общий с `SourceLayoutRule`) + ровно один psr-4 префикс (с завершающим `\`) на
  `src`/`./src/`. Нет/несколько бандл-классов — об этом сообщает `SourceLayoutRule`, а слои сравниваются без значения
  `BundleRoot`; нет префикса — своё сообщение. Отсутствующий файл сообщает `FileExistsRule`. `ruleset` — подмножество
  канона, `+Layer` запрещён. Значение в двойных кавычках YAML схлопывает `\\` → правило его отвергнет.
- Известное ограничение (принято, спек раздел 2): directory-collector'ы канона (`src/Application/.*`) deptrac
  оборачивает в `#…#i` и применяет к абсолютному пути без якоря; чекаут в каталоге вида `…/src/Application/…` или
  вложенный `src/Infrastructure/Src/Domain/Entity/` дали бы двойное членство. `%projectDirectory%` в regex
  потребовал бы экранирования пути — не делаем, в CI и у разработчиков таких путей нет.
- `SourceLayoutRule` — верхний уровень `src/` и `src/Domain/`; dot-файлы пропускаются, dot-каталоги — нарушение;
  PHP-файл под `src/Resources/` с объявлением class/interface/trait/enum — нарушение (вне всех слоёв deptrac).
- `CompilerPassLocationRule` — токенизация (`PhpToken`), без загрузки классов; файл без подстроки «pass» не
  токенизируется. Проверяются все классы файла, включая анонимные (скобки аргументов `new class (...)` учитываются);
  импорты сбрасываются на каждом `namespace`. Pass = прямой `implements CompilerPassInterface` или `extends` класса
  `Symfony\…Pass`. Наследник собственного абстрактного pass'а бандла не распознаётся (известное ограничение).
- `#[Override]` — PHPStan `checkMissingOverrideMethodAttribute: true` в `phpstan.dist.neon` php82/php84. Rector-правило
  `AddOverrideAttributeToOverriddenMethodsRector` не годится: при `withPhpVersion(PHP_82)` `PhpVersionedFilter`
  выкидывает его (минимум PHP 8.3), а на 8.3+ оно не трогает реализации интерфейсов (проверено 2026-10-19 UTC,
  Rector 2.7). PHPStan проверяет и при `phpVersion` 8.2–8.5. На PHP 8.2 класса `Override` нет — атрибуты не
  инстанцируются, это безопасно.
- `bin/verify-standard.php`: `StandardTemplateException` → exit 2 «The standard cannot be loaded»;
  `UnexpectedValueException` (нечитаемый каталог — итератор; нечитаемый файл — `BundleFile::read()`) → exit 2
  «The bundle cannot be read». Прочие исключения не перехватываются (ошибка верификатора). CLI-тесты с `chmod 000`
  пропускаются под root (root читает всё).
- Эталонный пакет `StandardDefinitionTest::testAcceptsTheReferenceBundle` — skeleton-bundle (рядом в checkout;
  в CI пропускается).

## Три уровня проверки и почему они разные

Три разных правила проверяют разную степень строгости не случайно, а
потому что у файлов разная легитимная вариативность между бандлами:

1. **Побайтовое сравнение (`ExactFileRule`)** — все конфиги инструментов
   (с v1.7): `.php-cs-fixer.dist.php`, `phpstan-ci.neon`, `rector.php`,
   `Makefile`, `phpunit.xml.dist`, `infection.json5`, `codecov.yml`,
   `.gitignore`. Специфика бандла живёт только в `deptrac.yaml`,
   `phpstan-baseline.neon`, composer-манифестах и inputs воркфлоу.
   `phpstan.dist.neon` — то же самое, но через `PhpstanConfigRule`, чтобы
   бандл мог поднять уровень выше 9.
2. **Проверка по ключевым значениям (`ContainsRule`,
   `ComposerManifestRule`)** — `checks.yml` (пин `@vX.Y.Z`) и
   `composer.json`.
3. **Существование/отсутствие (`FileExistsRule` / `FileAbsentRule`)** —
   для всего остального: файл обязан быть (`deptrac.yaml`,
   `infection.json5`, `LICENSE`, `SECURITY.md`, …) или обязан отсутствовать
   (`psalm.xml`, `psalm-baseline.xml` — стандарт использует только
   PHPStan). Содержимое файла в этом случае — забота самого бандла,
   стандарт следит только за фактом присутствия/отсутствия.

## `StandardDefinition`

`src/StandardDefinition.php` — единственное место, где набор правил
собирается в список (`StandardDefinition::rules($templatesDir)`). Любое
изменение состава стандарта (новое обязательное правило, новый шаблон)
вносится только здесь; `bin/verify-standard.php` и тесты на сам
верификатор его не дублируют.

## Профили среды выполнения

- `composer.json` → `extra.bundle-standard.runtime`: `php82` (по умолчанию, с v2.0.0), `php84` или `php74`. `RuntimeProfile::declaredBy()` читает его через `ComposerManifest::read()`; нет файла/ключа/битый JSON → `php82` (битый JSON сообщает `ComposerManifestRule`), неизвестное значение → `null`, его сообщает `RuntimeProfileRule` (значение — JSON из `declaration()`). `bin/verify-standard.php` при неизвестном профиле проверяет остальное по `php82` (`VerifyStandardCliTest`).
- `StandardDefinition::rules($templatesDir, $profile)`: шаблон из `templates/<profile>/`, если он там есть (`RuntimeProfile::template()`), иначе общий (для всех профилей одинаково). Общие шаблоны — профиль `php82`: phpstan `phpVersion: {min: 80200, max: 80599}`, rector `withPhpVersion(PHP_82)` + `withPhpSets(php82: true)`, cs-fixer `new_expression_parentheses: use_parentheses`. У `php84` свои три шаблона — общие с прежними значениями (`80400`, `php84`-сет, без `new_expression_parentheses`); `Php84TemplatesTest` сверяет точными заменами. У `php74` свои `phpstan.dist.neon` (70400), `.php-cs-fixer.dist.php` (без запятой после параметров), `rector.php` (`withPhpVersion(PHP_74)`, `php74`-сет, без `withAttributesSets`, `$lowestSymfony = '5.4.0'`), `phpunit.xml.dist` (PHPUnit 9.6). `Php74TemplatesTest` сравнивает их с общими точными заменами — расхождение общего шаблона с копией валит тест.
- `ComposerManifestRule($profile)`: `require.php` и ограничение `symfony/*` из профиля (`php74` — `^5.4|^6.4|^7.0|^8.0`, Symfony 6.4 требует PHP 8.1). `php82`/`php84` — `^7.4|^8.0`. `RectorTemplateTest` сверяет нижнюю версию Symfony каждого Rector-шаблона с ограничением профиля.
- Workflow: задание `minimal` (только `composer.json`) само читает профиль (`jq`, шаг `runtime`) и берёт PHP из таблицы, совпадающей с `RuntimeProfile::minimalPhp()` (`ReusableWorkflowTest` сверяет). При PHP ≠ `PRIMARY_PHP` там же `php -l` по одному файлу: до PHP 8.3 `php -l` проверяет только первый аргумент. Входа `minimal-php` нет — источник один.
- PHPUnit 9.6 не отличает свои deprecations от чужих: `convertDeprecationsToExceptions` валит сборку и на deprecation зависимости (осознанно, описано в README).
- Первый пользователь — `msstc4symfony/dto-generator-bridge` (работает в рантайме генератора DTO, PHP ≥ 7.4); ему хватает одной записи `symfony-versions`.
- Порядок релиза: слияние в `main` → тег на коммит, где `ref:` равен тегу → пуш тега → пуш пакетов, ссылающихся на этот тег. Матрица CI по умолчанию: PHP 8.2–8.5 × Symfony 7.4 / 8 (Symfony 8 только с PHP 8.4, поле `php` в `symfony-versions`), coverage на 8.4 × 8. Пакеты переходят на v2.0.0 только вместе с миграцией на PHP 8.2: verify-standard v2 у пакета с `>=8.4` без `runtime: php84` упадёт.

## Reusable workflow `php-bundle.yml`

- Все джобы блокирующие: `continue-on-error` запрещён
  (`ReusableWorkflowTest`).
- Roave BC check: база — последний СТАБИЛЬНЫЙ тег
  (`git describe --tags --abbrev=0 --exclude='*-*'`). Пропуск с
  `::notice::` только если стабильного тега нет, если ближайший тег —
  pre-release мажора выше базы (`v2.0.0-rc1` после `v1.3.0`), или если
  ветка push / target ветка PR ровно `N.x` / `N.M` / `release/N.M` /
  `vN.M` с N выше мажора базы (регулярка заякорена с обеих сторон).
  Pre-release минора (`v1.4.0-beta1`) проверку не отключает. При push тега
  (`GITHUB_REF_TYPE=tag`) база ищется от `HEAD^`, иначе тег сравнивался бы
  сам с собой; если у тега нет родителя (корневой коммит), сравнивать не с чем
  и шаг завершается успешно. Pre-release распознаётся и в форме `v2.0-rc1`. Ручной обход:
  `run-bc-check: false`. Скрипт шага исполняется в `BcCheckGateTest`
  против временного git-репо со стабом roave. Gotcha: каждый `=~`
  перезаписывает `BASH_REMATCH` — читать сразу после своего матча.
- Infection: `--min-msi`/`--min-covered-msi` из inputs
  `infection-min-msi`/`infection-min-covered-msi` (по умолчанию 55/55),
  плюс `--ignore-msi-with-no-mutations`. Замер 2026-10-02 UTC (локально,
  без apcu/memcache/memcached/rdkafka, поэтому CI не ниже): metrics 59.07, healthcheck 76.54,
  logger 82.46, profiling 86.60, tracing 94.59, bridge 97.67. Infection по
  умолчанию не учитывает непокрытые мутанты, поэтому MSI == covered MSI.
- Матрица PHPUnit (с v2.0.0): job `matrix` (sparse checkout `composer.json`) строит `include` через `jq` —
  все пары PHP × Symfony, кроме тех, где PHP ниже PHP профиля (та же таблица, что в `minimal`) или ниже поля
  `php` записи Symfony (сравнение массивов чисел; `tostring`, так что `[8.4]` без кавычек работает;
  нечисловое `nightly` → 999, выше всех), плюс ячейка prefer-lowest = первая оставшаяся пара
  (`composer update --prefer-lowest --prefer-stable`). Неизвестный профиль (и в `minimal`) или пустой результат → `::error::` и exit 1; `run-codecov` без ячейки PRIMARY_PHP × codecov → `::warning::` (GitHub
  иначе падает на пустой матрице без объяснения). Скрипт исполняется в `PhpunitMatrixTest` (нужен `jq`). `phpunit`
  берёт только `include: fromJSON(needs.matrix.outputs.include)`. Имена ячеек не изменились (required
  checks). Отключается `run-prefer-lowest: false`. Ячейки удаляют из `composer-ci.json` roave BC,
  deptrac и Infection (0.32 требует PHP 8.3) — те работают в своих job на 8.4.
- Elasticsearch (с `v1.1.0`): вход `elasticsearch` — JSON `list<{elastica, image}>`, по умолчанию `[]`.
  Job `elasticsearch` (`if: inputs.elasticsearch != '[]'`) — матрица по списку на `PRIMARY_PHP`:
  сервис ES из `matrix.target.image` (single-node, security off, порт 9200), из `composer-ci.json`
  убираются `roave/backward-compatibility-check` и `deptrac/deptrac` (они ограничивают зависимости),
  затем `composer require --dev ruflin/elastica:<constraint>`, `vendor/bin/phpunit --group elasticsearch`
  с `ELASTICSEARCH_URL=http://localhost:9200`. Бандлы без входа job не затрагивает (пропускается).
- `standard-check` чекаутит bundle-standard по `ref:`, равному тегу релиза;
  README-примеры обязаны совпадать (`ReusableWorkflowTest`).
- Prefer-lowest падает у всех шести бандлов. У profiling/tracing/bridge риск «Test code or tested
  code did not remove its own exception handlers» в Kernel-интеграционных
  тестах не лечится ни подъёмом Symfony, ни PHPUnit до 13, ни
  `symfony/error-handler` — виноват другой нижний транзитивный пакет
  (кандидаты: monolog-bundle 3.11 / monolog-bridge / var-dumper 6.3), нужен
  разбор по бандлу.

## Шаблон `rector.php`: Symfony-правила по нижней версии

- Rector 2.6.x больше не имеет `SymfonySetList::SYMFONY_64` и прочих
  версионных сетов: все Symfony-правила живут в одном
  `withComposerBased(symfony: true)`, каждое привязано к пакету и версии
  (`rector composer-based` показывает таблицу «Requires / Installed / Active»).
- Для `type != project` Rector сам берёт нижнюю границу constraint, но только
  у пакетов из `require`/`require-dev`. Транзитивные Symfony-пакеты
  (http-foundation, console, security-core…) резолвились в версию из
  vendor/ (8.x) — так `PushRequestToRequestStackConstructorRector` (7.2)
  переписал тесты logger-bundle на `new RequestStack([$r])`, а
  `Application::add → addCommand` (7.4) мог тихо попасть в src/.
- Шаблон теперь возвращает замыкание: биндит в контейнер Rector
  singleton `InstalledPackageResolver(__DIR__, <tmp>.json)`. Tmp-манифест
  (`tempnam(sys_get_temp_dir(), 'rector-lowest-packages-')`, свой на процесс,
  удаляется в shutdown) перечисляет все установленные пакеты в `require`:
  lockstep Symfony-пакеты (`symfony/*` с версией ≥ `$lowestSymfony` или `dev-*`;
  contracts, polyfills, monolog-bundle ниже порога и не трогаются) прижаты к
  `$lowestSymfony` (7.4.0 в общем шаблоне, 5.4.0 в `templates/php74/`), прямые зависимости — их объявленный constraint (Rector берёт нижнюю
  границу), остальные — установленная версия. Тот же резолвер использует
  `ComposerPackageConstraintFilter` и команда `composer-based`.
- Порог `$lowestSymfony` каждого Rector-шаблона дублирует нижнюю границу
  `RuntimeProfile::symfonyConstraint()` своего профиля (php84 — 7.4.0,
  php74 — 5.4.0); расхождение ловит `RectorTemplateTest`.
- Проверка: `vendor/bin/rector composer-based | grep symfony/ | grep -E '>=(7|8)'`
  не должна содержать `yes`.
