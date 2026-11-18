<?php

namespace App\Releve;

use Psr\Clock\ClockInterface;

/**
 * Whether a relevé's files are still the files that were sealed, said by
 * Sablier and not by this application.
 *
 * The seal is made on the owner's machine, with the owner's key (make seal
 * N=…), and committed with the relevé: releves/N/seal.sig, its tokens and its
 * Bitcoin proof. This only checks it, with the public keys of sablier.json, so
 * no private key ever reaches the cluster.
 *
 * Sablier is run on a copy of the relevé, in the temporary directory: the
 * image is read-only, and a proof the calendars have completed since it was
 * committed can then be completed and checked here. The result is Sablier's
 * own JSON (verify --json), kept on the volume, one file per relevé, with two
 * fields of ours: which seal it was about, and whether the proof was completed
 * here but not yet in the repository.
 */
final class SealVerifier
{
    /** A result older than this is checked again by the daily tick. */
    public const int RECHECK_DAYS = 7;

    public function __construct(
        private readonly string $sablierBin,
        private readonly string $sealsDir,
        private readonly string $declaration,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isSealed(Releve $releve): bool
    {
        return is_file($releve->dir.'/seal.sig');
    }

    /**
     * The last check, as Sablier wrote it, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function last(Releve $releve): ?array
    {
        $path = $this->resultPath($releve);
        if (!is_file($path)) {
            return null;
        }
        $result = json_decode((string) file_get_contents($path), true);

        return \is_array($result) ? $result : null;
    }

    /** Sablier has a result for this very seal: one resealed since is not checked yet. */
    public function isChecked(Releve $releve): bool
    {
        return $this->isSealed($releve)
            && ($this->last($releve)['seal_sha256'] ?? null) === hash_file('sha256', $releve->dir.'/seal.sig');
    }

    /** No check yet, a seal that changed since the last one, or a check too old. */
    public function isDue(Releve $releve): bool
    {
        if (!$this->isSealed($releve)) {
            return false;
        }
        if (!$this->isChecked($releve)) {
            return true;
        }
        $last = $this->last($releve);
        $checkedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) ($last['checked_at'] ?? ''));

        return false === $checkedAt || $checkedAt < $this->clock->now()->modify('-'.self::RECHECK_DAYS.' days');
    }

    /**
     * Run Sablier on the relevé and keep what it said.
     *
     * @return array<string, mixed> the result, also written to the volume
     */
    public function verify(Releve $releve): array
    {
        if (!$this->isSealed($releve)) {
            throw new \LogicException(\sprintf('Relevé %d is not sealed.', $releve->number));
        }

        $work = sys_get_temp_dir().'/seal-'.$releve->number.'-'.bin2hex(random_bytes(6));
        $copy = $work.'/'.$releve->number;
        $json = $work.'/result.json';
        try {
            $this->copy($releve->dir, $copy);
            [$code, $output] = $this->run([
                $this->sablierBin, 'verify', $copy.'/seal.sig',
                '--declare='.$this->declaration,
                '--json='.$json,
                '--lang=fr',
            ]);

            $result = is_file($json) ? json_decode((string) file_get_contents($json), true) : null;
            if (!\is_array($result)) {
                // Sablier did not get as far as its verdict: say what it printed.
                $result = [
                    'checked_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                    'valid' => false,
                    'error' => '' !== trim($output) ? trim($output) : 'Sablier n\'a rien répondu (code '.$code.').',
                ];
            }
            $result['seal_sha256'] = hash_file('sha256', $releve->dir.'/seal.sig');
            // The calendars had finished, the committed proof had not: the
            // relevé is anchored, and the repository should say so too.
            $result['completed_here'] = is_file($copy.'/seal.ots') && is_file($releve->dir.'/seal.ots')
                && hash_file('sha256', $copy.'/seal.ots') !== hash_file('sha256', $releve->dir.'/seal.ots');
            $result['output'] = $output;
        } finally {
            $this->remove($work);
        }

        $this->save($releve, $result);

        return $result;
    }

    private function resultPath(Releve $releve): string
    {
        return $this->sealsDir.'/'.$releve->number.'.json';
    }

    /** @param array<string, mixed> $result */
    private function save(Releve $releve, array $result): void
    {
        if (!is_dir($this->sealsDir) && !mkdir($this->sealsDir, 0o775, true) && !is_dir($this->sealsDir)) {
            throw new \RuntimeException('Cannot create '.$this->sealsDir);
        }
        // Written beside, then renamed: a page read during the write sees the
        // old result or the new one, never half of it.
        $tmp = $this->resultPath($releve).'.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        rename($tmp, $this->resultPath($releve));
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string} exit code, and what was printed
     */
    private function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return [127, 'Sablier ne s\'est pas lancé : '.$this->sablierBin];
        }
        // A few lines each: read one after the other, no pipe fills meanwhile.
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ('' !== $errors) {
            $output .= ('' === $output ? '' : "\n").$errors;
        }

        return [proc_close($process), $output];
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0o700, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to.'/'.substr($item->getPathname(), \strlen($from) + 1);
            if ($item->isLink()) {
                // Copied as a link, so the seal refuses it as it would here.
                symlink((string) readlink($item->getPathname()), $target);
            } elseif ($item->isDir()) {
                mkdir($target, 0o700);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
