<?php

namespace App\Security;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Model\TrustedDeviceInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The one admin. Not a database row: the account is the pod's environment
 * (Secret `observatoire`), so that the database holds readers and letters
 * only, and losing it never locks the owner out.
 */
final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, EmailTwoFactorInterface, TrustedDeviceInterface
{
    public const string USERNAME = 'admin';

    public function __construct(
        private readonly string $passwordHash,
        private readonly string $totpSecret,
        private readonly string $email = '',
        private ?string $emailCode = null,
    ) {
    }

    /** The second factor by email: a code sent to ADMIN_EMAIL, asked once per device. */
    public function isEmailAuthEnabled(): bool
    {
        return '' !== $this->email;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    public function getEmailAuthCode(): ?string
    {
        return $this->emailCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailCode = $authCode;
    }

    public function getUserIdentifier(): string
    {
        return self::USERNAME;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return '' !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return self::USERNAME;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return $this->isTotpAuthenticationEnabled()
            ? new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6)
            : null;
    }

    /**
     * A device trusted after one code stays trusted 30 days, unless the
     * password or the TOTP secret changes: the version follows both, so a new
     * create-secret.sh run forgets every device at once.
     *
     * Sixty bits of SHA-256 rather than CRC32 (Sablier flagged it, 2026-11-17):
     * a checksum beside a secret is the wrong tool, and with 32 bits one change
     * in four billion would have left every old device trusted.
     */
    public function getTrustedTokenVersion(): int
    {
        return (int) hexdec(substr(hash('sha256', $this->passwordHash.'|'.$this->totpSecret.'|'.$this->email), 0, 15));
    }
}
