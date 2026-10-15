<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Entity\Subscriber;
use App\Newsletter\Sender;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class NewsletterFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The client keeps one kernel across requests: the clock and the
        // database it holds are the ones the test sees.
        $this->client->disableReboot();
        $em = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        // The rate limiter's windows must not outlive one test.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testSubscribeConfirmReceiveUnsubscribe(): void
    {
        // 1. The form: a mail goes out, nothing is confirmed yet.
        $this->client->request('POST', '/newsletter', ['email' => ' Lecteur@Example.org '], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Vérifiez votre messagerie');
        $this->assertEmailCount(1);
        $subscriber = $this->subscriber('lecteur@example.org');
        $this->assertSame(Subscriber::PENDING, $subscriber->getStatus());

        // 2. The link in the mail: a GET only shows the button (scanners open links).
        $confirm = $this->link($this->getMailerMessage(0), '/confirmation/');
        $this->client->request('GET', $confirm);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->assertSame(Subscriber::PENDING, $this->subscriber('lecteur@example.org')->getStatus());

        $this->client->submitForm('Confirmer l\'inscription');
        $this->assertSelectorTextContains('h1', 'Inscription confirmée');
        $this->em()->clear();
        $subscriber = $this->subscriber('lecteur@example.org');
        $this->assertTrue($subscriber->isConfirmed());
        $this->assertNotNull($subscriber->getConfirmedAt());

        // 3. A letter scheduled for now goes out at the next tick, with the
        // mail client's one-click unsubscribe headers.
        $newsletter = new Newsletter('Relevé n° 2', "Le **relevé 2** est en ligne.\n\n<script>x</script>", $this->clock()->now());
        $newsletter->schedule($this->clock()->now());
        $this->em()->persist($newsletter);
        $this->em()->flush();
        $this->assertSame(1, static::getContainer()->get(Sender::class)->sendDue());
        $this->assertSame(Newsletter::SENT, $newsletter->getStatus());
        $this->assertSame(1, $newsletter->getRecipients());
        $this->assertSame(0, static::getContainer()->get(Sender::class)->sendDue(), 'never twice');

        // Counted since the last request: the letter alone.
        $this->assertEmailCount(1);
        $letter = $this->getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $letter);
        $this->assertEmailHtmlBodyContains($letter, '<strong>relevé 2</strong>');
        $this->assertEmailHtmlBodyNotContains($letter, '<script>');
        $this->assertEmailHeaderSame($letter, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $unsubscribe = $this->link($letter, '/desinscription/');
        $this->assertSame('<'.$unsubscribe.'>', $letter->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());

        // 4. One click from the mail client (RFC 8058): a POST, and the address is gone.
        $this->client->request('POST', $unsubscribe, ['List-Unsubscribe' => 'One-Click']);
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->assertNull(static::getContainer()->get(SubscriberRepository::class)->findOneBy(['email' => 'lecteur@example.org']));
    }

    public function testAnExpiredConfirmationLinkDoesNothing(): void
    {
        $this->client->request('POST', '/newsletter', ['email' => 'tard@example.org']);
        $confirm = $this->link($this->getMailerMessage(0), '/confirmation/');

        $this->clock()->modify('+8 days');
        $this->client->request('POST', $confirm);
        $this->assertResponseStatusCodeSame(404);
        $this->em()->clear();
        $this->assertFalse($this->subscriber('tard@example.org')->isConfirmed());
    }

    public function testTheAnswerNeverTellsWhetherAnAddressIsKnown(): void
    {
        $this->client->request('POST', '/newsletter', ['email' => 'deja@example.org']);
        $first = $this->client->getResponse()->getContent();
        $this->subscriber('deja@example.org')->confirm($this->clock()->now());
        $this->em()->flush();

        $this->client->request('POST', '/newsletter', ['email' => 'deja@example.org']);
        $this->assertSame($first, $this->client->getResponse()->getContent());
        $this->assertEmailCount(0, message: 'no second mail to a confirmed reader');
    }

    public function testRobotsAndOtherSitesAndFloodsAreTurnedAway(): void
    {
        $this->client->request('POST', '/newsletter', ['email' => 'robot@example.org', 'site' => 'http://spam']);
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);

        $this->client->request('POST', '/newsletter', ['email' => 'a@example.org'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/newsletter', ['email' => 'pas une adresse']);
        $this->assertResponseStatusCodeSame(422);

        // Per mailbox: three a day.
        for ($i = 0; $i < 3; ++$i) {
            $this->client->request('POST', '/newsletter', ['email' => 'cible@example.org']);
            $this->assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/newsletter', ['email' => 'cible@example.org']);
        $this->assertResponseStatusCodeSame(429);
    }

    public function testAForgedUnsubscribeLinkRemovesNobody(): void
    {
        $subscriber = new Subscriber('reste@example.org', 'test', $this->clock()->now());
        $subscriber->confirm($this->clock()->now());
        $this->em()->persist($subscriber);
        $this->em()->flush();

        $this->client->request('POST', '/desinscription/'.$subscriber->getId().'/'.str_repeat('0', 32));
        $this->assertResponseIsSuccessful();
        $this->em()->clear();
        $this->subscriber('reste@example.org');
    }

    private function link(?RawMessage $email, string $path): string
    {
        $this->assertInstanceOf(Email::class, $email);
        if (!preg_match('#http://localhost'.preg_quote($path, '#').'[^\s"<>]+#', (string) $email->getTextBody(), $m)) {
            $this->fail("no $path link in the mail");
        }

        return $m[0];
    }

    private function subscriber(string $email): Subscriber
    {
        $subscriber = static::getContainer()->get(SubscriberRepository::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($subscriber);

        return $subscriber;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function clock(): MockClock
    {
        $clock = static::getContainer()->get(ClockInterface::class);
        $this->assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }
}
