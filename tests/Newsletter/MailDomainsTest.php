<?php

namespace App\Tests\Newsletter;

use App\Newsletter\MailDomains;
use App\Newsletter\Subscriptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailDomainsTest extends TestCase
{
    #[DataProvider('domainProvider')]
    public function testTellsWhetherADomainTakesMail(string $domain, ?bool $expected): void
    {
        $this->assertSame($expected, (new MailDomains(new FakeDns()))->receivesMail($domain));
    }

    /** @return iterable<string, array{string, ?bool}> */
    public static function domainProvider(): iterable
    {
        yield 'with an MX' => ['example.org', true];
        yield 'an address and no MX' => ['address-only.test', true];
        yield 'neither' => ['no-mail.test', false];
        yield 'a null MX (RFC 7505)' => ['null-mx.test', false];
        yield 'DNS down: unknown, so nobody is refused' => ['dns-down.test', null];
    }

    #[DataProvider('mailboxProvider')]
    public function testCountsTheVariantsOfAnInboxAsOne(string $address, string $mailbox): void
    {
        $this->assertSame($mailbox, Subscriptions::mailbox($address));
    }

    /** @return iterable<string, array{string, string}> */
    public static function mailboxProvider(): iterable
    {
        yield 'plain' => ['jane@example.org', 'jane@example.org'];
        yield 'subaddress' => ['jane+news@example.org', 'jane@example.org'];
        yield 'gmail dots' => ['j.a.ne@gmail.com', 'jane@gmail.com'];
        yield 'googlemail' => ['jane+x@googlemail.com', 'jane@gmail.com'];
        yield 'dots elsewhere are kept' => ['j.ane@example.org', 'j.ane@example.org'];
    }
}
