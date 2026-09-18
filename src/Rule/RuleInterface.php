<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard\Rule;

use MaxShamaev\BundleStandard\Violation;

interface RuleInterface
{
    /**
     * @param non-empty-string $bundlePath absolute path to the bundle being verified
     *
     * @return list<Violation>
     */
    public function check(string $bundlePath): array;
}
