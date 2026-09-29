<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use InvalidArgumentException;

final readonly class Violation
{
    /**
     * @param non-empty-string $file
     * @param non-empty-string $message
     */
    public function __construct(
        public string $file,
        public string $message,
    ) {
        if ($file === '') {
            throw new InvalidArgumentException('Violation "file" must not be empty.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('Violation "message" must not be empty.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function format(): string
    {
        return $this->file . ': ' . $this->message;
    }
}
