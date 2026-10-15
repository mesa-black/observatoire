<?php

namespace App\Newsletter;

use App\Entity\Newsletter;
use App\Repository\NewsletterRepository;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Runs in the consumer, once a minute (App\Scheduler\Schedule): every
 * scheduled letter whose time has come goes out, once. Each mail is its own
 * queued message, retried on its own; the letter is marked as sending before
 * the first one, so that a crash halfway never sends it twice.
 */
final readonly class Sender
{
    public function __construct(
        private NewsletterRepository $newsletters,
        private SubscriberRepository $subscribers,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private Mails $mails,
        private ClockInterface $clock,
        private string $adminEmail,
    ) {
    }

    /** @return int letters sent */
    public function sendDue(): int
    {
        $sent = 0;
        foreach ($this->newsletters->due($this->clock->now()) as $newsletter) {
            $this->send($newsletter);
            ++$sent;
        }

        return $sent;
    }

    public function send(Newsletter $newsletter): void
    {
        $newsletter->startSending();
        $this->em->flush();

        $count = 0;
        foreach ($this->subscribers->confirmed() as $subscriber) {
            $this->mailer->send($this->mails->newsletter($newsletter, $subscriber->getEmail(), $subscriber));
            ++$count;
        }

        $newsletter->markSent($count, $this->clock->now());
        $this->em->flush();
    }

    public function sendTest(Newsletter $newsletter): void
    {
        $email = $this->mails->newsletter($newsletter, $this->adminEmail, null);
        $email->subject('[Test] '.$email->getSubject());
        $this->mailer->send($email);
    }
}
