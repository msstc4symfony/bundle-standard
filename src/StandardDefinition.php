<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use Msstc4Symfony\BundleStandard\Rule\ComposerManifestRule;
use Msstc4Symfony\BundleStandard\Rule\ContainsRule;
use Msstc4Symfony\BundleStandard\Rule\ExactFileRule;
use Msstc4Symfony\BundleStandard\Rule\FileAbsentRule;
use Msstc4Symfony\BundleStandard\Rule\FileExistsRule;
use Msstc4Symfony\BundleStandard\Rule\PhpstanConfigRule;
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
            // Byte-identical across bundles: bundle specifics belong in deptrac.yaml,
            // phpstan-baseline.neon, composer manifests and the workflow inputs.
            new ExactFileRule('.php-cs-fixer.dist.php', $templatesDir . '/.php-cs-fixer.dist.php'),
            new ExactFileRule('phpstan-ci.neon', $templatesDir . '/phpstan-ci.neon'),
            new PhpstanConfigRule('phpstan.dist.neon', $templatesDir . '/phpstan.dist.neon'),
            new ExactFileRule('rector.php', $templatesDir . '/rector.php'),
            new ExactFileRule('Makefile', $templatesDir . '/Makefile'),
            new ExactFileRule('phpunit.xml.dist', $templatesDir . '/phpunit.xml.dist'),
            new ExactFileRule('infection.json5', $templatesDir . '/infection.json5'),
            new ExactFileRule('codecov.yml', $templatesDir . '/codecov.yml'),
            // Stored without the leading dot so the template itself is not a live ignore file.
            new ExactFileRule('.gitignore', $templatesDir . '/gitignore'),

            // Pinned to a release tag, never @main: see README "Versioning".
            new ContainsRule('.github/workflows/checks.yml', [
                'uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v',
            ]),

            new ComposerManifestRule(),

            new FileExistsRule('composer-ci.json', 'CI installs the full optional dependency set'),
            new FileExistsRule('phpstan-baseline.neon', 'baseline must be explicit, not implied'),
            new FileExistsRule('deptrac.yaml', 'layer rules are mandatory'),
            new FileExistsRule('LICENSE', 'every bundle ships MIT'),
            new FileExistsRule('SECURITY.md', 'disclosure policy is mandatory'),
            new FileExistsRule('README.md', 'public documentation is mandatory'),
            new FileExistsRule('CLAUDE.md', 'agent guidance is part of the standard'),

            new FileAbsentRule('psalm.xml', 'the standard uses PHPStan only'),
            new FileAbsentRule('psalm-baseline.xml', 'the standard uses PHPStan only'),
        ];
    }
}
