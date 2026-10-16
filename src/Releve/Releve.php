<?php

namespace App\Releve;

/**
 * One relevé as the owner wrote it in releves/N/meta.json. The measurements
 * next to it are read by DataBuilder; this is only what decides when and how
 * the relevé is shown.
 */
final readonly class Releve
{
    public function __construct(
        public int $number,
        /** Day of the measurement, YYYY-MM-DD: also the prefix of its files. */
        public string $date,
        public \DateTimeImmutable $publishedAt,
        public string $title,
        public string $intro,
        public string $dir,
        /** "draft": true in meta.json: never published, whatever the date, until the flag goes. */
        public bool $draft = false,
    ) {
    }

    public function isPublishedAt(\DateTimeImmutable $now): bool
    {
        return !$this->draft && $this->publishedAt <= $now;
    }

    /** Waiting for its date: what the waiting page and a letter can count on. A draft is not. */
    public function isScheduledAt(\DateTimeImmutable $now): bool
    {
        return !$this->draft && $this->publishedAt > $now;
    }
}
