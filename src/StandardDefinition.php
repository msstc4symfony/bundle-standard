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
use Msstc4Symfony\BundleStandard\Rule\RuntimeProfileRule;

final readonly class StandardDefinition
{
    /**
     * @param non-empty-string $templatesDir
     *
     * @return list<RuleInterface>
     */
    public static function rules(string $templatesDir, RuntimeProfile $profile = RuntimeProfile::Php84): array
    {
        $templatesDir = rtrim($templatesDir, '/');
        if ($templatesDir === '') {
            $templatesDir = '/';
        }

        return [
            // Byte-identical across bundles of one runtime profile: bundle specifics belong in deptrac.yaml,
            // phpstan-baseline.neon, composer manifests and the workflow inputs.
            new ExactFileRule('.php-cs-fixer.dist.php', $profile->template($templatesDir, '.php-cs-fixer.dist.php')),
            new ExactFileRule('phpstan-ci.neon', $profile->template($templatesDir, 'phpstan-ci.neon')),
            new PhpstanConfigRule('phpstan.dist.neon', $profile->template($templatesDir, 'phpstan.dist.neon')),
            new ExactFileRule('rector.php', $profile->template($templatesDir, 'rector.php')),
            new ExactFileRule('Makefile', $profile->template($templatesDir, 'Makefile')),
            new ExactFileRule('phpunit.xml.dist', $profile->template($templatesDir, 'phpunit.xml.dist')),
            new ExactFileRule('infection.json5', $profile->template($templatesDir, 'infection.json5')),
            new ExactFileRule('codecov.yml', $profile->template($templatesDir, 'codecov.yml')),
            // Stored without the leading dot so the template itself is not a live ignore file.
            new ExactFileRule('.gitignore', $profile->template($templatesDir, 'gitignore')),

            // Pinned to a release tag, never @main: see README "Versioning".
            new ContainsRule('.github/workflows/checks.yml', [
                'uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v',
            ]),

            new RuntimeProfileRule(),
            new ComposerManifestRule($profile),

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
