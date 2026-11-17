<?php

namespace App\Tests\Controller;

use App\Releve\DataBuilder;
use App\Releve\ReleveRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CourseTest extends WebTestCase
{
    public function testTheCourseIsAPageOfItsOwnMeantToBeFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/comprendre');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(10, 'section.chapitre');
        $this->assertSelectorExists('#glossaire dt#g-x25519mlkem768');
        // The live test: its script carries this response's nonce, and its
        // paragraphs are hidden until the service answers.
        $nonce = (string) $client->getCrawler()->filter('script[src^="/cours.js"]')->attr('nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("'nonce-$nonce'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertSelectorCount(1, 'p.a-essayer[data-handshake][hidden]');
        $this->assertSelectorExists('#porte .encart-cours [data-handshake-encart][hidden] [data-groupe]');
        $this->assertSelectorCount(9, 'section.chapitre aside.encart-cours');
        // The number of doors is the latest published relevé's, not a figure
        // typed once and left behind.
        $latest = static::getContainer()->get(ReleveRepository::class)->latestPublished();
        $this->assertNotNull($latest);
        $doors = \count(static::getContainer()->get(DataBuilder::class)->doors($latest));
        $this->assertSelectorTextSame('#porte .encart-cours > b.gros', $doors." portes d'entrée");
        $this->assertSelectorTextContains('#porte .chapitre-texte', "pour $doors organisations françaises");
        $this->assertSelectorNotExists('meta[name="robots"]');
        // No link to itself in its footer; the other small pages keep it.
        $this->assertSelectorNotExists('.pied a[href="/comprendre"]');
        $client->request('GET', '/confidentialite');
        $this->assertSelectorExists('.pied a[href="/comprendre"]');
        $this->assertSelectorNotExists('.pied a[href="/confidentialite"]');

        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('/comprendre</loc>', (string) $client->getResponse()->getContent());
    }
}
