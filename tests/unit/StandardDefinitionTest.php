<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Test\Unit;

use Msstc4Symfony\BundleStandard\Rule\RuleInterface;
use Msstc4Symfony\BundleStandard\RuntimeProfile;
use Msstc4Symfony\BundleStandard\StandardDefinition;
use Msstc4Symfony\BundleStandard\Verifier;
use Msstc4Symfony\BundleStandard\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardDefinition::class)]
final class StandardDefinitionTest extends TestCase
{
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
            $php84 = $this->files((new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir));

            foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist', 'Makefile'] as $file) {
                self::assertNotContains($file, $php74, $file);
            }

            foreach (['phpstan.dist.neon', '.php-cs-fixer.dist.php', 'rector.php', 'phpunit.xml.dist'] as $file) {
                self::assertContains($file, $php84, $file);
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

    public function testReportsAnUnknownRuntimeProfile(): void
    {
        $bundleDir = sys_get_temp_dir() . '/unknown-profile-' . uniqid('', true);
        mkdir($bundleDir);
        file_put_contents($bundleDir . '/composer.json', '{"extra": {"bundle-standard": {"runtime": "php80"}}}');

        try {
            $messages = array_map(static fn (Violation $v): string => $v->format(), (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($bundleDir));

            self::assertContains('composer.json: extra.bundle-standard.runtime must be "php84" or "php74", found "php80"', $messages);
        } finally {
            unlink($bundleDir . '/composer.json');
            rmdir($bundleDir);
        }
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

    public function testAcceptsTheReferenceBundle(): void
    {
        $reference = __DIR__ . '/../../../logger-bundle';

        if (!is_dir($reference)) {
            self::markTestSkipped('logger-bundle checkout is not available next to bundle-standard');
        }

        $violations = (new Verifier(StandardDefinition::rules(self::TEMPLATES_DIR)))->verify($reference);

        self::assertSame(
            [],
            array_map(static fn (Violation $v): string => $v->format(), $violations),
        );
    }
}
