<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * /style.css?v=<hash of its content>: a stylesheet changed in the prototype
 * and synced here is a new URL, so the old one can be cached for a year
 * (the Caddyfile does) and a design change still shows up at once.
 */
final class AssetExtension
{
    /** @var array<string, string> per process: the files never change inside an image */
    private array $versions = [];

    public function __construct(private readonly string $publicDir)
    {
    }

    #[AsTwigFunction('versioned')]
    public function versioned(string $path): string
    {
        return $this->versions[$path] ??= $path.'?v='.substr((string) @hash_file('sha256', $this->publicDir.$path), 0, 12);
    }
}
