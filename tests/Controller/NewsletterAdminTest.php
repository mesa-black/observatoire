<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Repository\NewsletterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
        $this->assertSelectorTextContains('#apercu-corps', 'Le relevé 2 arrive.');

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

        $client->request('GET', '/admin/lettres');
        $this->assertAnySelectorTextContains('td', 'programmée (relevé 2)');
    }

    public function testWriteAndScheduleAtADateInOneForm(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/admin/lettres/nouvelle');
        $this->assertSelectorExists('input[type=date][name=at_date]');
        $this->assertSelectorExists('input[type=time][name=at_time]');

        // A past date is refused, and nothing is lost.
        $client->submitForm('Enregistrer le brouillon', ['subject' => 'Bientôt', 'body' => 'Un *mot*.', 'when' => 'at', 'at_date' => '2020-01-01', 'at_time' => '09:00']);
        $this->assertSelectorTextContains('.erreur', 'Cette date est passée');
        $this->assertInputValueSame('subject', 'Bientôt');

        $client->submitForm('Enregistrer le brouillon', ['when' => 'at', 'at_date' => '2099-07-14', 'at_time' => '10:30']);
        $newsletter = static::getContainer()->get(NewsletterRepository::class)->findOneBy([]);
        $this->assertNotNull($newsletter);
        $this->assertSame(Newsletter::SCHEDULED, $newsletter->getStatus());
        $this->assertSame('2099-07-14 08:30', $newsletter->getScheduledAt()?->format('Y-m-d H:i'), 'Paris 10:30 = 08:30 UTC in summer');
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'programmée');
        $this->assertSelectorTextContains('.entete h1', 'programmée');
    }

    public function testPreviewWithoutSaving(): void
    {
        $client = $this->freshClient();
        $client->request('POST', '/admin/lettres/apercu', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'CONTENT_TYPE' => 'application/json'], content: '{"body":"**gras** <script>x</script>"}');
        $this->assertResponseIsSuccessful();
        $html = json_decode((string) $client->getResponse()->getContent(), true)['html'];
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);

        // Without the script: the button re-renders the page, nothing saved.
        $client->request('GET', '/admin/lettres/nouvelle');
        $client->submitForm('Mettre à jour l\'aperçu', ['subject' => 'Essai', 'body' => 'Texte *aperçu*.']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#apercu-corps em', 'aperçu');
        $this->assertSame(0, static::getContainer()->get(NewsletterRepository::class)->count([]));
    }

    public function testEveryAdminPageRenders(): void
    {
        $client = $this->freshClient();
        foreach (['/admin' => 'Tableau de bord', '/admin/lettres' => 'Lettres', '/admin/releves' => 'Relevés', '/admin/abonnes' => 'Abonnés', '/admin/securite' => 'Sécurité'] as $url => $title) {
            $client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertSelectorTextContains('.entete h1', $title);
            $this->assertSelectorExists('.nav a[aria-current=page]');
        }
    }

    public function testTheDashboardShowsADraftNextToTheNextReleve(): void
    {
        $client = $this->freshClient();
        $client->request('GET', '/admin');
        $this->assertSelectorTextContains('.carte', 'N° 2');
        $this->assertSelectorTextContains('.carte .badge.draft', 'brouillon');
        $this->assertSelectorExists('a[href="/admin/releve/3"]');

        $client->request('GET', '/admin/releve/3');
        $this->assertResponseIsSuccessful();
    }

    private function freshClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->logInAdmin($client);

        return $client;
    }
}
