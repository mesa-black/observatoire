<?php

namespace App\Newsletter;

/**
 * Whether a domain can receive mail at all, asked before a confirmation is
 * sent: a typo ("gmial.com") or an invented domain would only bounce, and
 * every bounce costs the sender's reputation.
 *
 * Mail goes to the domain's MX hosts, or to the domain itself when it has none
 * but has an address (RFC 5321, 5.1). A single MX whose target is "." says the
 * domain takes no mail (RFC 7505). When the lookup fails, nobody is refused:
 * a resolver's bad minute is not the reader's fault.
 */
final readonly class MailDomains
{
    public function __construct(private Dns $dns)
    {
    }

    /** @return bool|null null when DNS could not tell */
    public function receivesMail(string $domain): ?bool
    {
        $mx = $this->dns->records($domain, \DNS_MX);
        if (null === $mx) {
            return null;
        }
        if ([] !== $mx) {
            $nullMx = 1 === \count($mx) && '' === rtrim((string) ($mx[0]['target'] ?? ''), '.');

            return !$nullMx;
        }

        $addresses = $this->dns->records($domain, \DNS_A | \DNS_AAAA);

        return null === $addresses ? null : [] !== $addresses;
    }
}
