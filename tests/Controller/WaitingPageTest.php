<?php

namespace App\Tests\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

final class WaitingPageTest extends WebTestCase
{
    public function testBeforeTheFirstReleveTheHomeAnnouncesItsDate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // A MockClock in the test environment (config/services.yaml, when@test).
        /** @var MockClock $clock */
        $clock = static::getContainer()->get(ClockInterface::class);
        // Five days before the fixture relevé 1 (2020-01-15 07:00, Paris).
        $clock->modify('2020-01-10 12:00:00');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('strong', 'Premier relevé le mercredi 15 janvier 2020 à 7 h');
        $this->assertCount(0, $crawler->filter('script#donnees'), 'nothing of the relevé before its time');
        $this->assertCount(1, $crawler->filter('form[action="/newsletter"]'));

        $client->request('GET', '/releve/1');
        $this->assertResponseStatusCodeSame(404);
    }
}
