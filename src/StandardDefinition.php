<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use Msstc4Symfony\BundleStandard\Rule\ComposerManifestRule;
use Msstc4Symfony\BundleStandard\Rule\ContainsRule;
use Msstc4Symfony\BundleStandard\Rule\ExactFileRule;
use Msstc4Symfony\BundleStandard\Rule\FileAbsentRule;
use Msstc4Symfony\BundleStandard\Rule\FileExistsRule;
use Msstc4Symfony\BundleStandard\Rule\RuleInterface;

final readonly class StandardDefinition
{
    /**
     * @param non-empty-string $templatesDir
     *
     * @return list<RuleInterface>
     */
    public static function rules(string $templatesDir): array
    {
        $templatesDir = rtrim($templatesDir, '/');

        return [
            new ExactFileRule('.php-cs-fixer.dist.php', $templatesDir . '/.php-cs-fixer.dist.php'),
            new ExactFileRule('phpstan-ci.neon', $templatesDir . '/phpstan-ci.neon'),

            new ContainsRule('rector.php', ['withPhpSets(php84: true)']),
            new ContainsRule('phpstan.dist.neon', [
                'level: 9',
                'phpVersion: 80400',
                'treatPhpDocTypesAsCertain: false',
            ]),
            // Invariant, not byte-identity: bundles are allowed extra targets
            // (e.g. metrics-bundle's integration-test targets) as long as the
            // mandatory seven are present.
            new ContainsRule('Makefile', [
                'check:',
                'test:',
                'test-with-coverage:',
                'infection:',
                'regenerate-baseline:',
                'fix:',
                'help:',
            ]),
            new ContainsRule('phpunit.xml.dist', [
                'failOnRisky="true"',
                'failOnWarning="true"',
                'failOnPhpunitDeprecation="true"',
                'beStrictAboutOutputDuringTests="true"',
            ]),

            // Pinned to a release tag, never @main: see README "Versioning".
            new ContainsRule('.github/workflows/checks.yml', [
                'uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v',
            ]),

            new ComposerManifestRule(),

            new FileExistsRule('composer-ci.json', 'CI installs the full optional dependency set'),
            new FileExistsRule('phpstan-baseline.neon', 'baseline must be explicit, not implied'),
            new FileExistsRule('deptrac.yaml', 'layer rules are mandatory'),
            new FileExistsRule('infection.json5', 'mutation testing is part of the standard'),
            new FileExistsRule('codecov.yml', 'coverage reporting is part of the standard'),
            new FileExistsRule('LICENSE', 'every bundle ships MIT'),
            new FileExistsRule('SECURITY.md', 'disclosure policy is mandatory'),
            new FileExistsRule('README.md', 'public documentation is mandatory'),
            new FileExistsRule('CLAUDE.md', 'agent guidance is part of the standard'),

            new FileAbsentRule('psalm.xml', 'the standard uses PHPStan only'),
            new FileAbsentRule('psalm-baseline.xml', 'the standard uses PHPStan only'),
        ];
    }
}
