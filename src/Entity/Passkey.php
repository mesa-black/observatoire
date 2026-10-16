<?php

namespace App\Entity;

use App\Repository\PasskeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A passkey of the one admin: the public key and counter WebAuthn verifies
 * against (the private key never leaves the device), and a name to tell them
 * apart ("MacBook", "iPhone").
 */
#[ORM\Entity(repositoryClass: PasskeyRepository::class)]
#[ORM\Table(name: 'passkey')]
class Passkey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The credential id, base64url: what the browser sends back at login. */
    #[ORM\Column(length: 255, unique: true)]
    private string $webauthnId;

    #[ORM\Column(length: 64)]
    private string $name;

    /** @var array<string, mixed> webauthn-lib's CredentialRecord, serialized. */
    #[ORM\Column(type: Types::JSON)]
    private array $record;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** @param array<string, mixed> $record */
    public function __construct(string $webauthnId, string $name, array $record, \DateTimeImmutable $now)
    {
        $this->webauthnId = $webauthnId;
        $this->name = $name;
        $this->record = $record;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getWebauthnId(): string { return $this->webauthnId; }
    public function getName(): string { return $this->name; }
    /** @return array<string, mixed> */
    public function getRecord(): array { return $this->record; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }

    /** @param array<string, mixed> $record */
    public function used(array $record, \DateTimeImmutable $now): void
    {
        $this->record = $record;
        $this->lastUsedAt = $now;
    }
}
