<?php

namespace App\Security;

use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the login code straight through the transport, not the messenger
 * queue: the admin is waiting on the page for it.
 */
final readonly class AdminCodeMailer implements AuthCodeMailerInterface
{
    public function __construct(
        #[Autowire(service: 'mailer.transports')] private TransportInterface $transport,
        #[Autowire('%env(MAILER_FROM)%')] private string $from,
    ) {
    }

    public function sendAuthCode(TwoFactorInterface $user): void
    {
        $code = (string) $user->getEmailAuthCode();
        $this->transport->send((new Email())
            ->from(Address::create($this->from))
            ->to($user->getEmailAuthRecipient())
            ->subject('Code de connexion : '.$code)
            ->text("Votre code de connexion à l'administration de l'Observatoire post-quantique :\n\n    $code\n\nIl est valable dix minutes. Si vous n'avez pas demandé à vous connecter, ignorez ce message : votre mot de passe est connu de quelqu'un d'autre, changez-le.\n"));
    }
}
