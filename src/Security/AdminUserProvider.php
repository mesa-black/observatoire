<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AdminUser> */
final readonly class AdminUserProvider implements UserProviderInterface
{
    public function __construct(
        #[\SensitiveParameter] private string $passwordHash,
        #[\SensitiveParameter] private string $totpSecret,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // No hash configured: no account, rather than an account with an empty password.
        if (AdminUser::USERNAME !== $identifier || '' === $this->passwordHash) {
            throw new UserNotFoundException();
        }

        return new AdminUser($this->passwordHash, $this->totpSecret);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AdminUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return AdminUser::class === $class;
    }
}
