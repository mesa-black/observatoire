<?php

namespace App\Entity;

use App\Repository\SubscriberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Everything kept about a reader: the address, and the proof that its owner
 * asked (the request, then a click on a link only that mailbox received).
 * No name, no IP address, no opening or click tracking. Unsubscribing deletes
 * the row: there is then nothing left to keep.
 */
#[ORM\Entity(repositoryClass: SubscriberRepository::class)]
#[ORM\Table(name: 'subscriber')]
class Subscriber
{
    public const string PENDING = 'pending';
    public const string CONFIRMED = 'confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 254, unique: true)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    /** Which wording of the form the person agreed to (templates/newsletter/_form.html.twig). */
    #[ORM\Column(length: 32)]
    private string $consentVersion;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(string $email, string $consentVersion, \DateTimeImmutable $requestedAt)
    {
        $this->email = $email;
        $this->consentVersion = $consentVersion;
        $this->requestedAt = $requestedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isConfirmed(): bool
    {
        return self::CONFIRMED === $this->status;
    }

    public function getConsentVersion(): string
    {
        return $this->consentVersion;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /** A new request for a pending address: the consent asked for is the latest. */
    public function renew(string $consentVersion, \DateTimeImmutable $at): void
    {
        $this->consentVersion = $consentVersion;
        $this->requestedAt = $at;
    }

    public function confirm(\DateTimeImmutable $at): void
    {
        if (!$this->isConfirmed()) {
            $this->status = self::CONFIRMED;
            $this->confirmedAt = $at;
        }
    }
}
