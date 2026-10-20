<?php

namespace App\Newsletter;

/** DNS lookups, behind an interface so that tests never touch the network. */
interface Dns
{
    /**
     * @param int $type a DNS_* constant, or several joined with |
     *
     * @return list<array<string, mixed>>|null the records, an empty list when there are none, null when the lookup itself failed
     */
    public function records(string $domain, int $type): ?array;
}
