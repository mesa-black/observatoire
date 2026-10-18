<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CourseTest extends WebTestCase
{
    use LogsInAdmin;

    public function testTheCourseIsAPageOfItsOwnMeantToBeFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/comprendre');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(9, 'section.chapitre');
        $this->assertSelectorExists('#glossaire dt#g-x25519mlkem768');
        // The live test: its script carries this response's nonce, and its
        // paragraphs are hidden until the service answers.
        $nonce = (string) $client->getCrawler()->filter('script[src^="/cours.js"]')->attr('nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("'nonce-$nonce'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertSelectorCount(2, 'p.a-essayer[data-handshake][hidden]');
        $this->assertSelectorNotExists('meta[name="robots"]');

        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('/comprendre</loc>', (string) $client->getResponse()->getContent());
    }

    public function testACourseReleveIsPreviewedAsTheCourse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->logInAdmin($client);

        $client->request('GET', '/admin/releve/4');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.releve-cours', 'Relevé n° 4 · 1 juin 2099');
        $this->assertSelectorCount(9, 'section.chapitre');
        $this->assertSelectorNotExists('#donnees');
    }
}
