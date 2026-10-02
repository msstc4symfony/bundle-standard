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
- `PhpstanConfigRule` (с v1.8.0) — `phpstan.dist.neon` побайтово равен
  шаблону, кроме первой строки `level:` (хвостовой `# comment` допустим);
  уровень — строго один из `9`, `10`, `max` (уровней выше 10 в PHPStan 2
  нет, `09`/`11` отклоняются). Шаблон остаётся на `level: 9`, пока все шесть
  бандлов не чисты на 10 (на 2026-10-02 UTC чисты только profiling-bundle
  и metrics-bridge-profiling).
- `ComposerManifestRule` — точечные проверки `composer.json`: нет поля
  `version`, вендор `msstc4symfony`, лицензия `MIT`, `require.php`
  строго `>=8.4`, `autoload-dev.psr-4` не содержит чужих неймспейсов
  (защита от copy-paste, когда в новый бандл случайно попадает
  тестовый неймспейс другого бандла). Каждый `symfony/*` в `require` —
  `^6.4|^7.0|^8.0`, кроме `symfony/monolog-bundle` и contracts
  (`symfony/contracts`, `symfony/*-contracts`): у них своя линейка мажоров,
  поэтому допустим любой constraint, где каждая альтернатива привязана к
  одному мажору (`^3`, `~3.1`, `3.5.1`, `3.*`, допускается префикс `v`;
  дефисные диапазоны, `<`/`>`, `*`, `dev-*` — нарушение).
- `MissingFileViolationTrait` — общий guard «файл отсутствует»,
  переиспользуется `ExactFileRule` и `ContainsRule` перед их
  специфичной проверкой (сначала убеждаемся, что есть что сравнивать).

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

## Reusable workflow `php-bundle.yml` (v1.8.1)

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
  сам с собой. Pre-release распознаётся и в форме `v2.0-rc1`. Ручной обход:
  `run-bc-check: false`. Скрипт шага исполняется в `BcCheckGateTest`
  против временного git-репо со стабом roave. Gotcha: каждый `=~`
  перезаписывает `BASH_REMATCH` — читать сразу после своего матча.
- Infection: `--min-msi`/`--min-covered-msi` из inputs
  `infection-min-msi`/`infection-min-covered-msi` (по умолчанию 55/55),
  плюс `--ignore-msi-with-no-mutations`. Замер 2026-10-02 UTC (локально,
  без apcu/memcache/memcached/rdkafka, поэтому CI не ниже): metrics 59.07, healthcheck 76.54,
  logger 82.46, profiling 86.60, tracing 94.59, bridge 97.67. Infection по
  умолчанию не учитывает непокрытые мутанты, поэтому MSI == covered MSI.
- Prefer-lowest: доп. ячейка матрицы `phpunit` через динамический
  `include` (`format()` с `{{`/`}}` как экранированием фигурных скобок) на
  первом элементе `php-versions` и `symfony-versions`, `composer update
  --prefer-lowest --prefer-stable`. Имена остальных ячеек не меняются
  (required checks). Отключается `run-prefer-lowest: false`.
- `standard-check` чекаутит bundle-standard по `ref:`, равному тегу релиза;
  README-примеры обязаны совпадать (`ReusableWorkflowTest`).
- Prefer-lowest на 2026-10-02 UTC падает у всех шести бандлов (см.
  отчёт релиза v1.8.0). У profiling/tracing/bridge риск «Test code or tested
  code did not remove its own exception handlers» в Kernel-интеграционных
  тестах не лечится ни подъёмом Symfony до 6.4.32, ни PHPUnit до 13, ни
  `symfony/error-handler` — виноват другой нижний транзитивный пакет
  (кандидаты: monolog-bundle 3.11 / monolog-bridge / var-dumper 6.3), нужен
  разбор по бандлу.

## Шаблон `rector.php`: Symfony-правила по нижней версии (с v1.8.1)

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
  lockstep Symfony-пакеты (`symfony/*` с версией ≥ 6.4.0 или `dev-*`;
  contracts, polyfills, monolog-bundle ниже порога и не трогаются) прижаты к
  `6.4.0`, прямые зависимости — их объявленный constraint (Rector берёт нижнюю
  границу), остальные — установленная версия. Тот же резолвер использует
  `ComposerPackageConstraintFilter` и команда `composer-based`.
- Порог `$lowestSymfony = '6.4.0'` в шаблоне дублирует нижнюю границу
  `ComposerManifestRule::SYMFONY_CONSTRAINT`; расхождение ловит
  `RectorTemplateTest`.
- Проверка: `vendor/bin/rector composer-based | grep symfony/ | grep -E '>=(7|8)'`
  не должна содержать `yes`.
