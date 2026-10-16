<?php

namespace App\Releve;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A link that opens a relevé before its time, for whoever holds it: to check
 * how a link preview (LinkedIn's inspector) renders it. Signed with
 * APP_SECRET, so it cannot be guessed, and tied to the relevé's number.
 */
final readonly class PreviewLinks
{
    public function __construct(#[Autowire('%env(APP_SECRET)%')] private string $secret)
    {
    }

    public function token(int $number): string
    {
        return substr(hash_hmac('sha256', 'apercu:'.$number, $this->secret), 0, 24);
    }

    public function isValid(int $number, string $token): bool
    {
        return hash_equals($this->token($number), $token);
    }
}
