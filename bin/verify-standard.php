#!/usr/bin/env php
<?php

declare(strict_types=1);

use Msstc4Symfony\BundleStandard\RuntimeProfile;
use Msstc4Symfony\BundleStandard\StandardDefinition;
use Msstc4Symfony\BundleStandard\Verifier;

// Installed as a dependency, this file sits at vendor/msstc4symfony/bundle-standard/bin/;
// autoload.php is three levels up. Running from the repo checkout, it is one level up.
$installedAutoload = __DIR__ . '/../../../autoload.php';
$repoAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($installedAutoload)) {
    require_once $installedAutoload;
} elseif (is_file($repoAutoload)) {
    require_once $repoAutoload;
} else {
    fwrite(STDERR, "Could not locate Composer autoloader (checked vendor-parent and ../vendor).\n");

    exit(2);
}

$bundlePath = $argv[1] ?? null;

if (!is_string($bundlePath) || !is_dir($bundlePath)) {
    fwrite(STDERR, "Usage: verify-standard.php <path-to-bundle>\n");

    exit(2);
}

$bundlePath = rtrim((string) realpath($bundlePath), '/');
// An unknown profile is reported by RuntimeProfileRule; the rest is checked against the default one.
$profile = RuntimeProfile::declaredBy($bundlePath) ?? RuntimeProfile::Php84;
$verifier = new Verifier(StandardDefinition::rules(__DIR__ . '/../templates', $profile));
$violations = $verifier->verify($bundlePath);

if ($violations === []) {
    fwrite(STDOUT, sprintf("Bundle at %s complies with the standard.\n", $bundlePath));

    exit(0);
}

fwrite(STDERR, sprintf("Bundle at %s violates the standard:\n", $bundlePath));

foreach ($violations as $violation) {
    fwrite(STDERR, '  - ' . $violation->format() . "\n");
}

exit(1);
