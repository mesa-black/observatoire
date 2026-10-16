<?php

namespace App\Tests\Newsletter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport;

/**
 * The production DSN must build a transport. Tests send through null://, so
 * a missing dependency of the Brevo API transport (symfony/http-client, on
 * 2026-10-16) only showed as a 500 on the first real subscription.
 */
final class MailerTransportTest extends TestCase
{
    public function testTheBrevoApiDsnBuildsItsTransport(): void
    {
        $this->assertInstanceOf(BrevoApiTransport::class, Transport::fromDsn('brevo+api://xkeysib-not-a-key@default'));
    }
}
