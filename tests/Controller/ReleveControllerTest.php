<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReleveControllerTest extends WebTestCase
{
    use LogsInAdmin;

    public function testHomeShowsTheLatestPublishedReleveWithItsDataAsJson(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Relevé n° 1');
        $json = $crawler->filter('script#donnees[type="application/json"]')->text();
        $this->assertSame('2020-01-01', json_decode($json, false, 512, \JSON_THROW_ON_ERROR)->date);
        $this->assertCount(0, $crawler->filter('script:not([src]):not([type="application/json"]):not([type="application/ld+json"])'), 'no executable inline script');
        $this->assertCount(1, $crawler->filter('form[action="/newsletter"]'));
        $this->assertResponseNotHasCookie('observatoire_admin');
        $this->assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testAReleveStillToComeIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/releve/1');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/releve/2');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheAdminCanPreviewIt(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/releve/2');
        $this->assertResponseRedirects('/admin/connexion');

        $this->logInAdmin($client);
        $client->request('GET', '/admin/releve/2');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Relevé n° 2');
    }

    public function testAWrongPasswordDoesNotLogIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/connexion');
        $client->submitForm('Se connecter', ['_password' => 'wrong']);
        $client->request('GET', '/admin');
        $this->assertResponseRedirects('/admin/connexion');
    }
}
