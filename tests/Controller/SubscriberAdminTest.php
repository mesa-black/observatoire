<?php

namespace App\Tests\Controller;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriberAdminTest extends WebTestCase
{
    use LogsInAdmin;

    public function testReadersAndPendingRequestsAreListedApart(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/admin/abonnes');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.entete h1', 'Abonnés');
        $this->assertSelectorTextContains('#confirmes', 'lu@example.org');
        $this->assertSelectorTextNotContains('#confirmes', 'attend@example.org');
        $this->assertSelectorTextContains('#en-attente', 'attend@example.org');
        $this->assertSelectorTextContains('#en-attente h2', '1 en attente');
        $this->assertSelectorExists('.nav a[aria-current=page][href="/admin/abonnes"]');
        $this->assertSelectorExists('form[data-confirm]');
    }

    public function testDeletingAnAddressRemovesItsRow(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/admin/abonnes');
        $client->submit($client->getCrawler()->filter('#en-attente form')->form());

        $this->assertResponseRedirects('/admin/abonnes');
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'attend@example.org supprimé');
        $this->assertNull(static::getContainer()->get(SubscriberRepository::class)->findOneBy(['email' => 'attend@example.org']));
        $this->assertNotNull(static::getContainer()->get(SubscriberRepository::class)->findOneBy(['email' => 'lu@example.org']));
    }

    public function testDeletingNeedsTheFormsToken(): void
    {
        $client = $this->freshClient();
        $id = static::getContainer()->get(SubscriberRepository::class)->findOneBy(['email' => 'lu@example.org'])?->getId();
        $client->request('POST', "/admin/abonnes/$id/supprimer", ['_token' => 'forged']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertNotNull(static::getContainer()->get(SubscriberRepository::class)->find((int) $id));
    }

    private function freshClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $now = new \DateTimeImmutable();
        $reader = new Subscriber('lu@example.org', 'form-2026-10', $now->modify('-3 days'));
        $reader->confirm($now->modify('-2 days'));
        $em->persist($reader);
        $em->persist(new Subscriber('attend@example.org', 'form-2026-10', $now->modify('-1 day')));
        $em->flush();

        $this->logInAdmin($client);

        return $client;
    }
}
