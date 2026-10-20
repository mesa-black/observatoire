<?php

namespace App\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * login_throttling counts password and passkey attempts, not the second
 * factor: a six-digit code would fall to a million guesses once the password
 * is known. Every code typed is counted per client, before it is checked; past
 * the limit it is refused unchecked.
 */
final readonly class SecondFactorThrottle implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $secondFactorLimiter,
        private ClientKey $clientKey,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
            // As login_throttling does: a completed login starts the count over.
            TwoFactorAuthenticationEvents::COMPLETE => 'onComplete',
        ];
    }

    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $limit = $this->limiter($event)->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyLoginAttemptsAuthenticationException((int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
        }
    }

    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $this->limiter($event)->reset();
    }

    private function limiter(TwoFactorAuthenticationEvent $event): LimiterInterface
    {
        return $this->secondFactorLimiter->create($this->clientKey->of($event->getRequest()->getClientIp()));
    }
}
