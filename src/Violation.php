<?php

declare(strict_types=1);

namespace MaxShamaev\BundleStandard;

final readonly class Violation
{
    public function __construct(
        public string $file,
        public string $message,
    ) {
    }

    public function format(): string
    {
        return $this->file . ': ' . $this->message;
    }
}
