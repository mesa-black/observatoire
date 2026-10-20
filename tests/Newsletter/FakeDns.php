<?php

namespace App\Tests\Newsletter;

use App\Newsletter\Dns;

/**
 * The DNS of the tests: every domain takes mail, except a few named after what
 * they stand for. Never the network.
 */
final class FakeDns implements Dns
{
    public function records(string $domain, int $type): ?array
    {
        $mx = \DNS_MX === $type;

        return match ($domain) {
            'no-mail.test' => [],
            'null-mx.test' => $mx ? [['host' => $domain, 'type' => 'MX', 'pri' => 0, 'target' => '']] : [],
            'address-only.test' => $mx ? [] : [['host' => $domain, 'type' => 'A', 'ip' => '192.0.2.1']],
            'dns-down.test' => null,
            default => $mx ? [['host' => $domain, 'type' => 'MX', 'pri' => 10, 'target' => 'mx.'.$domain]] : [],
        };
    }
}
