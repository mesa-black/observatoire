<?php

namespace App\Security;

use Scheb\TwoFactorBundle\Model\PersisterInterface;

/** scheb/2fa stores the generated code through this: into AdminCodeStore. */
final readonly class AdminCodePersister implements PersisterInterface
{
    public function __construct(private AdminCodeStore $codes)
    {
    }

    public function persist(object $user): void
    {
        if ($user instanceof AdminUser) {
            $this->codes->set($user->getEmailAuthCode());
        }
    }
}
