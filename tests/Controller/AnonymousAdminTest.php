<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sessions live on a small disk: a visitor who is not logged in must not be
 * able to create them, nor to make the site name another host.
 */
final class AnonymousAdminTest extends WebTestCase
{
    public function testTheLoginPageOpensNoSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/connexion');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->sessionStarted($client));
    }

    public function testAnAdminPageSendsAnAnonymousVisitorToTheLoginWithoutASession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/abonnes');

        $this->assertResponseRedirects('/admin/connexion');
        $this->assertFalse($this->sessionStarted($client));
    }

    public function testAnUnknownHostIsRefused(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_HOST' => 'evil.example']);

        $this->assertResponseStatusCodeSame(400);
    }

    private function sessionStarted(KernelBrowser $client): bool
    {
        $request = $client->getRequest();

        return $request->hasSession() && $request->getSession()->isStarted();
    }
}
