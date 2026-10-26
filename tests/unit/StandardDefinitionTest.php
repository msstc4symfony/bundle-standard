<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\PackageKind;
use Msstc4Symfony\BundleStandard\Rule\CompilerPassLocationRule;
use Msstc4Symfony\BundleStandard\Rule\DeptracConfigRule;
use Msstc4Symfony\BundleStandard\Rule\PackageKindRule;
use Msstc4Symfony\BundleStandard\Rule\RuleInterface;
use Msstc4Symfony\BundleStandard\Rule\SourceLayoutRule;
use Msstc4Symfony\BundleStandard\RuntimeProfile;
use Msstc4Symfony\BundleStandard\StandardDefinition;
use Msstc4Symfony\BundleStandard\Test\Unit\Rule\TemporaryBundleTrait;
use Msstc4Symfony\BundleStandard\Verifier;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardDefinition::class)]
final class StandardDefinitionTest extends TestCase
{
    use TemporaryBundleTrait;

    private const string TEMPLATES_DIR = __DIR__ . '/../../templates';

    public function testProducesOnlyRuleInstances(): void
    {
        $rules = StandardDefinition::rules(self::TEMPLATES_DIR);

        self::assertNotEmpty($rules);

        foreach ($rules as $rule) {
            self::assertInstanceOf(RuleInterface::class, $rule);
        }
    }

    public function testReportsEverythingMissingOnAnEmptyDirectory(): void
    {
        $emptyDir = sys_get_temp_dir() . '/empty-bundle-' . uniqid('', true);
        mkdir($emptyDir);

        try {
            $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($emptyDir);

            $files = array_map(static fn (Violation $v): string => $v->file, $violations);

            self::assertContains('Makefile', $files);
            self::assertContains('composer.json', $files);
            self::assertContains('deptrac.yaml', $files);
            self::assertContains('src', $files);
            self::assertContains('infection.json5', $files);
            self::assertContains('.github/workflows/checks.yml', $files);
        } finally {
            rmdir($emptyDir);
        }
    }

    public function testAcceptsAStricterPhpstanLevelThanTheTemplate(): void
    {
        $bundleDir = sys_get_temp_dir() . '/level-bundle-' . uniqid('', true);
        mkdir($bundleDir);
        $template = file_get_contents(self::TEMPLATES_DIR . '/phpstan.dist.neon');
        self::assertIsString($template);
        file_put_contents($bundleDir . '/phpstan.dist.neon', preg_replace('/^(\s*level:\s*)\S+$/m', '${1}10', $template));

        try {
            $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir);

            $files = array_map(static fn (Violation $v): string => $v->file, $violations);

            self::assertNotContains('phpstan.dist.neon', $files);
        } finally {
            unlink($bundleDir . '/phpstan.dist.neon');
            rmdir($bundleDir);
        }
    }

    public function testComparesAPhp74BundleWithItsOwnTemplates(): void
    {
        $bundleDir = sys_get_temp_dir() . '/php74-bundle-' . uniqid('', true);
        mkdir($bundleDir);
        foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist'] as $file) {
            copy(self::TEMPLATES_DIR . '/php74/' . $file, $bundleDir . '/' . $file);
        }

        copy(self::TEMPLATES_DIR . '/Makefile', $bundleDir . '/Makefile');
        mkdir($bundleDir . '/.github/workflows', 0o777, true);
        file_put_contents($bundleDir . '/.github/workflows/checks.yml', "    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.0.0\n");

        try {
            $php74 = $this->files((new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR, RuntimeProfile::Php74)))->verify($bundleDir));
            $default = $this->files((new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir));

            foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist', 'Makefile'] as $file) {
                self::assertNotContains($file, $php74, $file);
            }

            foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist'] as $file) {
                self::assertContains($file, $default, $file);
            }

            // The workflow reads the profile from composer.json itself: checks.yml needs nothing extra.
            self::assertNotContains('.github/workflows/checks.yml', $php74);
        } finally {
            foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist', 'Makefile', '.github/workflows/checks.yml'] as $file) {
                unlink($bundleDir . '/' . $file);
            }

            rmdir($bundleDir . '/.github/workflows');
            rmdir($bundleDir . '/.github');
            rmdir($bundleDir);
        }
    }

    public function testComparesAPhp84BundleWithItsOwnTemplates(): void
    {
        $files = ['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php'];
        $bundleDir = sys_get_temp_dir() . '/php84-bundle-' . uniqid('', true);
        mkdir($bundleDir);
        foreach ($files as $file) {
            copy(self::TEMPLATES_DIR . '/php84/' . $file, $bundleDir . '/' . $file);
        }

        try {
            $php84 = $this->files((new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR, RuntimeProfile::Php84)))->verify($bundleDir));
            $default = $this->files((new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir));

            foreach ($files as $file) {
                self::assertNotContains($file, $php84, $file);
                self::assertContains($file, $default, $file);
            }
        } finally {
            foreach ($files as $file) {
                unlink($bundleDir . '/' . $file);
            }

            rmdir($bundleDir);
        }
    }

    public function testReportsAnUnknownRuntimeProfile(): void
    {
        $bundleDir = sys_get_temp_dir() . '/unknown-profile-' . uniqid('', true);
        mkdir($bundleDir);
        file_put_contents($bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php80"}}}');

        try {
            $messages = array_map(static fn (Violation $v): string => $v->format(), (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir));

            self::assertContains('composer.json: extra.bundle-standard.runtime must be "php82" or "php84" or "php74", found "php80"', $messages);
        } finally {
            unlink($bundleDir . '/composer.json');
            rmdir($bundleDir);
        }
    }

    public function testAppliesTheLayoutRulesExceptUnderPhp74(): void
    {
        $classes = static fn (RuntimeProfile $profile): array => array_map(
            static fn (RuleInterface $rule): string => $rule::class,
            StandardDefinition::rules(self::TEMPLATES_DIR, $profile),
        );

        foreach ([RuntimeProfile::Php82, RuntimeProfile::Php84] as $profile) {
            self::assertContains(DeptracConfigRule::class, $classes($profile));
            self::assertContains(SourceLayoutRule::class, $classes($profile));
            self::assertContains(CompilerPassLocationRule::class, $classes($profile));
        }

        self::assertNotContains(DeptracConfigRule::class, $classes(RuntimeProfile::Php74));
        self::assertNotContains(SourceLayoutRule::class, $classes(RuntimeProfile::Php74));
        self::assertNotContains(CompilerPassLocationRule::class, $classes(RuntimeProfile::Php74));
    }

    public function testAcceptsAMinimalLibrary(): void
    {
        $this->createLibrary();

        try {
            $kind = PackageKind::declaredBy($this->bundleDir);
            self::assertSame(PackageKind::Library, $kind);
            $profile = RuntimeProfile::declaredBy($this->bundleDir) ?? RuntimeProfile::Php82;

            self::assertSame([], $this->messages(StandardDefinition::rules(self::TEMPLATES_DIR, $profile, $kind)));
        } finally {
            $this->removeBundle();
        }
    }

    public function testReportsALeftoverCodecovConfig(): void
    {
        $this->createLibrary();
        $this->write('codecov.yml', "coverage: {}\n");

        try {
            $kind = PackageKind::declaredBy($this->bundleDir) ?? PackageKind::Bundle;
            $profile = RuntimeProfile::declaredBy($this->bundleDir) ?? RuntimeProfile::Php82;

            self::assertSame(
                ['codecov.yml: must not exist: nothing uploads coverage to Codecov since bundle-standard v4.0.0'],
                $this->messages(StandardDefinition::rules(self::TEMPLATES_DIR, $profile, $kind)),
            );
        } finally {
            $this->removeBundle();
        }
    }

    public function testChecksALibraryAgainstTheBundleRulesWithoutItsKind(): void
    {
        $this->createLibrary();

        try {
            self::assertSame([
                'composer.json: must declare conflict.symfony/symfony = "*"',
                'composer.json: must declare extra.symfony.require',
                'deptrac.yaml: deptrac.layers must declare the 8 canonical layers of bundle-standard templates/deptrac.yaml, found 6: copy them as-is, with the BundleRoot value set to the bundle class',
                'src: must contain exactly one <Name>Bundle.php, found 0',
            ], $this->messages(StandardDefinition::rules(self::TEMPLATES_DIR)));
        } finally {
            $this->removeBundle();
        }
    }

    public function testReportsAnUnknownPackageKind(): void
    {
        $bundleDir = sys_get_temp_dir() . '/unknown-kind-' . uniqid('', true);
        mkdir($bundleDir);
        file_put_contents($bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"kind": "plugin"}}}');

        try {
            self::assertContains(
                'composer.json: extra.bundle-standard.kind must be "bundle" or "library", found "plugin"',
                $this->messages(StandardDefinition::rules(self::TEMPLATES_DIR), $bundleDir),
            );
        } finally {
            unlink($bundleDir . '/composer.json');
            rmdir($bundleDir);
        }
    }

    public function testAppliesTheLibraryLayoutUnlessThePhp74ProfileSkipsIt(): void
    {
        $classes = static fn (RuntimeProfile $profile, PackageKind $kind): array => array_map(
            static fn (RuleInterface $rule): string => $rule::class,
            StandardDefinition::rules(self::TEMPLATES_DIR, $profile, $kind),
        );

        foreach (PackageKind::cases() as $kind) {
            self::assertContains(PackageKindRule::class, $classes(RuntimeProfile::Php82, $kind));
            self::assertContains(PackageKindRule::class, $classes(RuntimeProfile::Php74, $kind));
            self::assertContains(SourceLayoutRule::class, $classes(RuntimeProfile::Php84, $kind));
            self::assertNotContains(SourceLayoutRule::class, $classes(RuntimeProfile::Php74, $kind));
            self::assertNotContains(DeptracConfigRule::class, $classes(RuntimeProfile::Php74, $kind));
        }
    }

    public function testAcceptsTheReferenceBundle(): void
    {
        $reference = __DIR__ . '/../../../skeleton-bundle';

        if (!is_dir($reference)) {
            self::markTestSkipped('skeleton-bundle checkout is not available next to bundle-standard');
        }

        $profile = RuntimeProfile::declaredBy($reference) ?? RuntimeProfile::Php82;
        $kind = PackageKind::declaredBy($reference) ?? PackageKind::Bundle;
        $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR, $profile, $kind)))->verify($reference);

        self::assertSame(
            [],
            array_map(static fn (Violation $v): string => $v->format(), $violations),
        );
    }

    /**
     * Every file the standard requires, as a library without Symfony would ship it.
     */
    private function createLibrary(): void
    {
        $this->createBundle();

        $copies = [
            '.php-cs-fixer.dist.php', 'phpstan-ci.neon', 'phpstan.dist.neon', 'rector.php', 'Makefile', 'phpunit.xml.dist',
            'infection.json5',
        ];
        foreach ($copies as $file) {
            $this->write($file, (string) file_get_contents(self::TEMPLATES_DIR . '/' . $file));
        }

        $this->write('.gitignore', (string) file_get_contents(self::TEMPLATES_DIR . '/gitignore'));
        $this->write('deptrac.yaml', (string) file_get_contents(self::TEMPLATES_DIR . '/deptrac-library.yaml'));
        $this->write('.github/workflows/checks.yml', "jobs:\n  standard:\n    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v3.3.0\n");
        $this->write('composer.json', json_encode([
            'name' => 'msstc4symfony/money',
            'license' => 'MIT',
            'authors' => [['name' => 'Maxim Shamaev', 'email' => 'maxim.shamaev@gmail.com']],
            'require' => ['php' => '>=8.2'],
            'autoload' => ['psr-4' => ['Msstc4Symfony\\Money\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Msstc4Symfony\\Money\\Test\\' => 'tests/']],
            'extra' => ['bundle-standard' => ['kind' => 'library']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach (['composer-ci.json' => '{}', 'phpstan-baseline.neon' => '', 'LICENSE' => 'MIT', 'SECURITY.md' => '#', 'README.md' => '#', 'CLAUDE.md' => '#'] as $file => $content) {
            $this->write($file, $content);
        }

        $this->write('src/Domain/ValueObject/Money.php', "<?php\n\nnamespace Msstc4Symfony\\Money\\Domain\\ValueObject;\n\nfinal readonly class Money\n{\n}\n");
        $this->write('src/Resources/currencies.json', '[]');
    }

    /**
     * @param list<RuleInterface> $rules
     * @param non-empty-string|null $bundleDir
     *
     * @return list<string>
     */
    private function messages(array $rules, ?string $bundleDir = null): array
    {
        return array_map(static fn (Violation $v): string => $v->format(), (new Verifier($rules))->verify($bundleDir ?? $this->bundleDir));
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    private function files(array $violations): array
    {
        return array_map(static fn (Violation $v): string => $v->file, $violations);
    }
}
