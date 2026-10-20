<?php

namespace App\Newsletter;

/** The resolver the pod is configured with (the cluster's DNS). */
final class SystemDns implements Dns
{
    public function records(string $domain, int $type): ?array
    {
        // The trailing dot keeps the resolver from appending the cluster's
        // search domains to a name that does not resolve.
        $records = @dns_get_record(rtrim($domain, '.').'.', $type);

        return false === $records ? null : $records;
    }
}
