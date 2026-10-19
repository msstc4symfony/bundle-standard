<?php

declare(strict_types=1);

namespace Msstc4Symfony\BundleStandard;

use RuntimeException;

/**
 * A template shipped with the standard cannot be used: the standard is broken, not the bundle being verified.
 */
final class StandardTemplateException extends RuntimeException
{
}
