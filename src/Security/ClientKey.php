<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The key a rate limiter counts a client by. Never the address itself: the
 * limiters' state is written to disk for the length of their window, and the
 * privacy notice says no IP address is kept. A plain hash of an IPv4 address
 * is reversed in seconds (there are only 2^32), so it is keyed with the app
 * secret.
 *
 * An IPv6 client is counted by its /64: one connection usually holds the whole
 * block, and counting each address apart would leave no limit at all.
 */
final readonly class ClientKey
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function of(?string $ip): string
    {
        $ip ??= 'unknown';
        if (str_contains($ip, ':')) {
            $ip = IpUtils::anonymize($ip, v6Bytes: 8);
        }

        return hash_hmac('sha256', $ip, $this->secret);
    }
}
