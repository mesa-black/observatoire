<?php

namespace App\Newsletter;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Double opt-in: an address is only written to once its owner clicks a link
 * sent to it. The answer to a request never says whether the address was
 * already known, so the form cannot be used to test who reads us.
 */
final readonly class Subscriptions
{
    /** Bumped whenever the wording next to the form changes (templates/newsletter/_form.html.twig). */
    public const string CONSENT_VERSION = 'form-2026-10';

    public function __construct(
        private SubscriberRepository $subscribers,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private Mails $mails,
        private Tokens $tokens,
        private ClockInterface $clock,
        private RateLimiterFactoryInterface $subscribeIpLimiter,
        private RateLimiterFactoryInterface $subscribeEmailLimiter,
    ) {
    }

    public static function normalize(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        return \strlen($email) <= 254 && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @throws TooManyRequests when this client or this address asked too often
     */
    public function request(string $email, ?string $clientIp): void
    {
        // The address of origin lives in the limiter's cache only, for the
        // length of its window; it is never written next to the subscriber.
        if (!$this->subscribeIpLimiter->create($clientIp ?? 'unknown')->consume()->isAccepted()
            || !$this->subscribeEmailLimiter->create(hash('sha256', $email))->consume()->isAccepted()) {
            throw new TooManyRequests();
        }

        $now = $this->clock->now();
        $subscriber = $this->subscribers->findOneBy(['email' => $email]);
        if (null === $subscriber) {
            $subscriber = new Subscriber($email, self::CONSENT_VERSION, $now);
            $this->em->persist($subscriber);
        } elseif ($subscriber->isConfirmed()) {
            // Already a reader: nothing to confirm, nothing to say.
            return;
        } else {
            $subscriber->renew(self::CONSENT_VERSION, $now);
        }
        $this->em->flush();

        $this->mailer->send($this->mails->confirmation($subscriber));
    }

    public function find(int $id): ?Subscriber
    {
        return $this->subscribers->find($id);
    }

    public function isValidConfirm(Subscriber $subscriber, int $expires, string $token): bool
    {
        return $this->tokens->isValidConfirm($subscriber, $expires, $token, $this->clock->now()->getTimestamp());
    }

    public function confirm(Subscriber $subscriber): void
    {
        $subscriber->confirm($this->clock->now());
        $this->em->flush();
    }

    public function isValidUnsubscribe(Subscriber $subscriber, string $token): bool
    {
        return $this->tokens->isValidUnsubscribe($subscriber, $token);
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $this->em->remove($subscriber);
        $this->em->flush();
    }
}
