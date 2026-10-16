<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Repository\NewsletterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsletterAdminTest extends WebTestCase
{
    use LogsInAdmin;

    public function testWriteTestAndScheduleForAReleve(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $this->logInAdmin($client);
        $client->request('GET', '/admin/lettres/nouvelle');
        $client->submitForm('Enregistrer le brouillon', ['subject' => 'Relevé n° 2', 'body' => 'Le relevé **2** arrive.']);
        $client->followRedirect();
        $this->assertSelectorTextContains('.apercu', 'Le relevé 2 arrive.');

        $client->submitForm('Envoyer un test à admin@example.test');
        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(0) ?? throw new \LogicException(), 'Subject', '[Test] Relevé n° 2');
        $client->followRedirect();

        // Relevé 2 of the fixtures is still to come: it is offered as a moment.
        $client->submitForm('Programmer l\'envoi', ['when' => 'releve:2']);
        $newsletter = static::getContainer()->get(NewsletterRepository::class)->findOneBy([]);
        $this->assertNotNull($newsletter);
        $this->assertSame(Newsletter::SCHEDULED, $newsletter->getStatus());
        $this->assertSame(2, $newsletter->getReleveNumber());
        $this->assertSame('2099-01-01 06:00', $newsletter->getScheduledAt()?->format('Y-m-d H:i'), 'Paris 07:00 = 06:00 UTC in winter');

        $client->request('GET', '/admin');
        $this->assertAnySelectorTextContains('td', 'programmée (relevé 2)');
    }
}
