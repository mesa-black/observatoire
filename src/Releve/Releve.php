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
    ) {
    }

    public function isPublishedAt(\DateTimeImmutable $now): bool
    {
        return $this->publishedAt <= $now;
    }
}
