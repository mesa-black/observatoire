<?php

namespace App\Releve;

use Psr\Clock\ClockInterface;

/**
 * Relevés are files in the image, not rows: they are written once, reviewed in
 * Git, and published by the clock. A relevé whose publication time has not
 * come does not exist for the public (404), exactly as the blog's dated
 * versions do.
 */
final class ReleveRepository
{
    public const string TIMEZONE = 'Europe/Paris';

    /** @var array<int, Releve>|null read once per process: the files never change inside an image */
    private ?array $all = null;

    public function __construct(
        private readonly string $relevesDir,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<int, Releve> by number, ascending */
    public function all(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }
        $all = [];
        foreach (glob($this->relevesDir.'/*/meta.json') ?: [] as $file) {
            $releve = $this->load($file);
            $all[$releve->number] = $releve;
        }
        ksort($all);

        return $this->all = $all;
    }

    /** Any relevé, published or not: for the admin's preview. */
    public function find(int $number): ?Releve
    {
        return $this->all()[$number] ?? null;
    }

    public function findPublished(int $number): ?Releve
    {
        $releve = $this->find($number);

        return null !== $releve && $releve->isPublishedAt($this->clock->now()) ? $releve : null;
    }

    public function latestPublished(): ?Releve
    {
        $now = $this->clock->now();
        $latest = null;
        foreach ($this->all() as $releve) {
            if ($releve->isPublishedAt($now)) {
                $latest = $releve;
            }
        }

        return $latest;
    }

    /** The first relevé whose time has not come, if any: what the waiting page announces. */
    public function nextScheduled(): ?Releve
    {
        $now = $this->clock->now();
        foreach ($this->all() as $releve) {
            if ($releve->isScheduledAt($now)) {
                return $releve;
            }
        }

        return null;
    }

    private function load(string $file): Releve
    {
        $meta = json_decode((string) file_get_contents($file), true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($meta)) {
            throw new \UnexpectedValueException("$file: not a JSON object");
        }
        $dir = \dirname($file);
        $number = $meta['number'] ?? null;
        if (!\is_int($number) || basename($dir) !== (string) $number) {
            throw new \UnexpectedValueException("$file: number must be the directory's name");
        }
        $date = (string) ($meta['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \UnexpectedValueException("$file: date must be YYYY-MM-DD");
        }
        // Paris wall-clock time, as the owner thinks of it ("le 16 à 7 h"):
        // never UTC, never an offset to work out by hand.
        $publishedAt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            (string) ($meta['published'] ?? ''),
            new \DateTimeZone(self::TIMEZONE),
        );
        if (false === $publishedAt) {
            throw new \UnexpectedValueException("$file: published must be \"YYYY-MM-DD HH:MM\" (Paris time)");
        }

        $page = (string) ($meta['page'] ?? Releve::MEASUREMENTS);
        if (!\in_array($page, [Releve::MEASUREMENTS, Releve::COURSE], true)) {
            throw new \UnexpectedValueException("$file: page must be \"mesures\" or \"cours\"");
        }

        return new Releve(
            $number,
            $date,
            $publishedAt,
            (string) ($meta['title'] ?? 'Relevé n° '.$number),
            (string) ($meta['intro'] ?? ''),
            $dir,
            true === ($meta['draft'] ?? false),
            $page,
        );
    }
}
