<?php

namespace App\Newsletter;

use App\Entity\Subscriber;

/**
 * The links in the mails carry their own proof: an HMAC of the subscriber's
 * id and address with the application secret. Nothing to store, nothing to
 * expire by hand. The address is part of what is signed: a link made for one
 * address can never act on another, whatever happens to the ids.
 */
final readonly class Tokens
{
    /** Long enough to come back from holidays to, short enough not to linger. */
    public const int CONFIRM_TTL = 7 * 24 * 3600;

    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        // An HMAC with an empty key is still an HMAC, and anyone can compute it.
        if (\strlen($secret) < 16) {
            throw new \LogicException('APP_SECRET must be set (16 characters at least).');
        }
    }

    public function confirm(Subscriber $subscriber, int $expires): string
    {
        return $this->sign('confirm', (string) $subscriber->getId(), $subscriber->getEmail(), (string) $expires);
    }

    public function isValidConfirm(Subscriber $subscriber, int $expires, string $token, int $now): bool
    {
        return $expires >= $now && hash_equals($this->confirm($subscriber, $expires), $token);
    }

    public function unsubscribe(Subscriber $subscriber): string
    {
        return $this->sign('unsubscribe', (string) $subscriber->getId(), $subscriber->getEmail());
    }

    public function isValidUnsubscribe(Subscriber $subscriber, string $token): bool
    {
        return hash_equals($this->unsubscribe($subscriber), $token);
    }

    private function sign(string ...$parts): string
    {
        // 128 bits are plenty for a link, and keep the URL short.
        return substr(hash_hmac('sha256', implode("\0", $parts), $this->secret), 0, 32);
    }
}
