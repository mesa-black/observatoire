<?php

namespace App\Newsletter;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use App\Security\ClientKey;
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
        private RateLimiterFactoryInterface $subscribeAllLimiter,
        private ClientKey $clientKey,
        private MailDomains $mailDomains,
    ) {
    }

    public static function normalize(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        return \strlen($email) <= 254 && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * The mailbox an address reaches, for counting requests per inbox only:
     * "jane+1@" and "jane+2@" land in the same one, and so do "j.ane@gmail.com"
     * and "jane@googlemail.com". The address kept is the one given.
     */
    public static function mailbox(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];
        $local = explode('+', $local, 2)[0];
        if (\in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            [$local, $domain] = [str_replace('.', '', $local), 'gmail.com'];
        }

        return $local.'@'.$domain;
    }

    /**
     * @throws TooManyRequests      when this client, this inbox, or everyone together asked too often
     * @throws UndeliverableAddress when the address's domain takes no mail
     */
    public function request(string $email, ?string $clientIp): void
    {
        // Counted by a keyed hash of the client (see ClientKey) and of the
        // inbox: neither is written in clear, and neither next to the
        // subscriber. The last limit caps what the form can make us send in a
        // day, whatever the number of clients.
        if (!$this->subscribeIpLimiter->create($this->clientKey->of($clientIp))->consume()->isAccepted()
            || !$this->subscribeEmailLimiter->create(hash('sha256', self::mailbox($email)))->consume()->isAccepted()
            || !$this->subscribeAllLimiter->create('all')->consume()->isAccepted()) {
            throw new TooManyRequests();
        }
        // After the limits, so that the form cannot be used to make us query
        // DNS without bound.
        if (false === $this->mailDomains->receivesMail(substr($email, (int) strrpos($email, '@') + 1))) {
            throw new UndeliverableAddress();
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
