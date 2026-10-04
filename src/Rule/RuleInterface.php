<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard\Rule;

use Msstc4Symfony\BundleStandard\Violation;

interface RuleInterface
{
    /**
     * @param non-empty-string $bundlePath absolute path to the bundle being verified
     *
     * @return list<Violation>
     */
    public function check(string $bundlePath): array;
}
