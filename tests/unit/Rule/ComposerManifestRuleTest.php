<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit\Rule;

use Msstc4Symfony\BundleStandard\Rule\ComposerManifestRule;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerManifestRule::class)]
final class ComposerManifestRuleTest extends TestCase
{
    private const array AUTHOR = ['name' => 'Maxim Shamaev', 'email' => 'maxim.shamaev@gmail.com'];

    /** @var non-empty-string */
    private string $bundleDir;

    protected function setUp(): void
    {
        $this->bundleDir = sys_get_temp_dir() . '/bundle-' . uniqid('', true);
        mkdir($this->bundleDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->bundleDir . '/*');

        foreach (($files !== false ? $files : []) as $file) {
            unlink($file);
        }

        rmdir($this->bundleDir);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->bundleDir . '/composer.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );
    }

    public function testPassesOnCompliantManifest(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'license' => 'MIT',
            'authors' => [self::AUTHOR],
            'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^6.4|^7.0|^8.0'],
            'conflict' => ['symfony/symfony' => '*'],
            'extra' => ['symfony' => ['require' => '^6.4|^7.0|^8.0']],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\Test\\Unit\\' => 'tests/unit/']],
        ]);

        self::assertSame([], (new ComposerManifestRule())->check($this->bundleDir));
    }

    public function testReportsVersionField(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'version' => '1.0.1',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4'],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must not declare a "version" field; versions come from git tags', $messages);
    }

    public function testReportsForeignDevAutoloadNamespace(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4'],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['MaxShamaev\\HealthCheckBundle\\Test\\Unit\\' => 'tests/unit/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains(
            'autoload-dev namespace "MaxShamaev\\HealthCheckBundle\\Test\\Unit\\" does not share the package root namespace "MaxShamaev\\LoggerBundle\\"',
            $messages,
        );
    }

    public function testReportsWrongLicenseVendorAndPhpFloor(): void
    {
        $this->writeManifest([
            'name' => 'hot-ecosystem/tracing-bundle',
            'license' => 'proprietary',
            'require' => ['php' => '>=8.1'],
            'autoload' => ['psr-4' => ['Hot\\TracingBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must be licensed MIT, found "proprietary"', $messages);
        self::assertContains('must live under the "msstc4symfony" vendor, found "hot-ecosystem"', $messages);
        self::assertContains('must require php ">=8.4", found ">=8.1"', $messages);
    }

    public function testReportsMissingNameField(): void
    {
        $this->writeManifest([
            'license' => 'MIT',
            'require' => ['php' => '>=8.4'],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must declare a package "name"', $messages);
    }

    public function testReportsMissingLicenseAsNone(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'require' => ['php' => '>=8.4'],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must be licensed MIT, found "none"', $messages);
    }

    public function testAcceptsAuthorAmongOtherAuthors(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'authors' => [['name' => 'Someone Else'], self::AUTHOR],
        ]);

        self::assertNotContains(
            'must list author "Maxim Shamaev <maxim.shamaev@gmail.com>"',
            $this->messages(),
        );
    }

    /**
     * @param array<string, mixed> $authors
     */
    #[DataProvider('provideNonCompliantAuthors')]
    public function testReportsMissingOrWrongAuthor(array $authors): void
    {
        $this->writeManifest(['name' => 'msstc4symfony/logger-bundle', ...$authors]);

        self::assertContains('must list author "Maxim Shamaev <maxim.shamaev@gmail.com>"', $this->messages());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideNonCompliantAuthors(): iterable
    {
        yield 'absent' => [[]];
        yield 'not a list' => [['authors' => 'Maxim Shamaev']];
        yield 'wrong email' => [['authors' => [['name' => 'Maxim Shamaev', 'email' => 'other@example.com']]]];
        yield 'email missing' => [['authors' => [['name' => 'Maxim Shamaev']]]];
    }

    public function testReportsMissingPhpRequirementAsNone(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'license' => 'MIT',
            'require' => [],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must require php ">=8.4", found "none"', $messages);
    }

    public function testReportsMissingAutoloadRootNamespace(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4'],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must declare an autoload.psr-4 root namespace', $messages);
    }

    public function testReportsMalformedJsonWithoutThrowing(): void
    {
        file_put_contents($this->bundleDir . '/composer.json', '{ this is not valid json');

        $violations = (new ComposerManifestRule())->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertSame('composer.json', $violations[0]->file);
        self::assertStringStartsWith('contains invalid JSON:', $violations[0]->message);
    }

    public function testReportsWrongSymfonyConstraint(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/tracing-bundle',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^7.2'],
            'conflict' => ['symfony/symfony' => '*'],
            'extra' => ['symfony' => ['require' => '^6.4|^7.0|^8.0']],
            'autoload' => ['psr-4' => ['MaxShamaev\\TracingBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains(
            'require.symfony/framework-bundle must use constraint "^6.4|^7.0|^8.0", found "^7.2"',
            $messages,
        );
    }

    public function testDoesNotFlagNonLockstepSymfonyPackages(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/logger-bundle',
            'license' => 'MIT',
            'authors' => [self::AUTHOR],
            'require' => [
                'php' => '>=8.4',
                'symfony/framework-bundle' => '^6.4|^7.0|^8.0',
                'symfony/monolog-bundle' => '^3.0',
            ],
            'conflict' => ['symfony/symfony' => '*'],
            'extra' => ['symfony' => ['require' => '^6.4|^7.0|^8.0']],
            'autoload' => ['psr-4' => ['MaxShamaev\\LoggerBundle\\' => 'src/']],
        ]);

        self::assertSame([], (new ComposerManifestRule())->check($this->bundleDir));
    }

    public function testReportsMissingConflict(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/tracing-bundle',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^6.4|^7.0|^8.0'],
            'extra' => ['symfony' => ['require' => '^6.4|^7.0|^8.0']],
            'autoload' => ['psr-4' => ['MaxShamaev\\TracingBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must declare conflict.symfony/symfony = "*"', $messages);
    }

    public function testReportsMissingExtraSymfonyRequire(): void
    {
        $this->writeManifest([
            'name' => 'msstc4symfony/tracing-bundle',
            'license' => 'MIT',
            'require' => ['php' => '>=8.4', 'symfony/framework-bundle' => '^6.4|^7.0|^8.0'],
            'conflict' => ['symfony/symfony' => '*'],
            'autoload' => ['psr-4' => ['MaxShamaev\\TracingBundle\\' => 'src/']],
        ]);

        $messages = array_map(
            static fn (Violation $v): string => $v->message,
            (new ComposerManifestRule())->check($this->bundleDir),
        );

        self::assertContains('must declare extra.symfony.require', $messages);
    }

    public function testReportsWhenDecodedManifestIsNotAnObject(): void
    {
        file_put_contents(
            $this->bundleDir . '/composer.json',
            json_encode('just a string', JSON_THROW_ON_ERROR),
        );

        $violations = (new ComposerManifestRule())->check($this->bundleDir);

        self::assertCount(1, $violations);
        self::assertSame('must decode to a JSON object', $violations[0]->message);
    }
}
