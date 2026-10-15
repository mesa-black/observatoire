<?php

namespace App\Entity;

use App\Repository\NewsletterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One letter to every confirmed reader. Written as a draft, then scheduled
 * (now, a given time, or the publication of a relevé); the consumer sends it
 * at that time, once, and records to how many addresses.
 */
#[ORM\Entity(repositoryClass: NewsletterRepository::class)]
#[ORM\Table(name: 'newsletter')]
class Newsletter
{
    public const string DRAFT = 'draft';
    public const string SCHEDULED = 'scheduled';
    public const string SENDING = 'sending';
    public const string SENT = 'sent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 16)]
    private string $status = self::DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    /** Set when the letter waits for a relevé: shown in the admin, the time is in scheduledAt. */
    #[ORM\Column(nullable: true)]
    private ?int $releveNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private int $recipients = 0;

    public function __construct(string $subject, string $body, \DateTimeImmutable $createdAt)
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /** Markdown: rendered to HTML for the mail, sent as is for the text part. */
    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isEditable(): bool
    {
        return self::DRAFT === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getReleveNumber(): ?int
    {
        return $this->releveNumber;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getRecipients(): int
    {
        return $this->recipients;
    }

    public function edit(string $subject, string $body): void
    {
        if (!$this->isEditable()) {
            throw new \LogicException('Only a draft can be edited.');
        }
        $this->subject = $subject;
        $this->body = $body;
    }

    public function schedule(\DateTimeImmutable $at, ?int $releveNumber = null): void
    {
        if (!$this->isEditable()) {
            throw new \LogicException('Only a draft can be scheduled.');
        }
        $this->status = self::SCHEDULED;
        $this->scheduledAt = $at;
        $this->releveNumber = $releveNumber;
    }

    public function unschedule(): void
    {
        if (self::SCHEDULED !== $this->status) {
            throw new \LogicException('Only a scheduled letter can go back to draft.');
        }
        $this->status = self::DRAFT;
        $this->scheduledAt = null;
        $this->releveNumber = null;
    }

    public function startSending(): void
    {
        if (self::SCHEDULED !== $this->status) {
            throw new \LogicException('Only a scheduled letter can be sent.');
        }
        $this->status = self::SENDING;
    }

    public function markSent(int $recipients, \DateTimeImmutable $at): void
    {
        $this->status = self::SENT;
        $this->recipients = $recipients;
        $this->sentAt = $at;
    }
}
