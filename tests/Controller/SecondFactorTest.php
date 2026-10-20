<?php

namespace App\Tests\Controller;

use App\Security\AdminCodeStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Once the password is known, the six-digit code must not be guessable. */
final class SecondFactorTest extends WebTestCase
{
    protected function tearDown(): void
    {
        // The exhausted limit must not lock the next tests out.
        static::getContainer()->get('cache.rate_limiter')->clear();
        parent::tearDown();
    }

    public function testAfterFiveWrongCodesEvenTheRightOneIsRefused(): void
    {
        $client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();

        $client->request('GET', '/admin/connexion');
        $client->submitForm('Se connecter', ['_password' => 'secret']);
        while ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        $this->assertStringEndsWith('/admin/2fa', $client->getRequest()->getPathInfo());

        for ($i = 0; $i < 5; ++$i) {
            $client->submitForm('Valider', ['_auth_code' => '000000']);
            $client->followRedirect();
        }

        /** @var AdminCodeStore $codes */
        $codes = static::getContainer()->get(AdminCodeStore::class);
        $client->submitForm('Valider', ['_auth_code' => (string) $codes->get()]);
        $client->followRedirect();

        $this->assertStringEndsWith('/admin/2fa', $client->getRequest()->getPathInfo());
        $client->request('GET', '/admin');
        $this->assertResponseRedirects();
        $this->assertStringNotContainsString('Tableau de bord', (string) $client->getResponse()->getContent());
    }
}
