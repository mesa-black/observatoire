<?php

namespace App\Tests\Releve;

use App\Releve\Releve;
use App\Releve\SealVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** When the daily tick asks Sablier again, and when it leaves a seal alone. */
final class SealVerifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/seal-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/releve', 0o700, true);
        mkdir($this->dir.'/seals');
        file_put_contents($this->dir.'/releve/meta.json', '{}');
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->dir.'/releve/*') ?: [], ...glob($this->dir.'/seals/*') ?: []] as $file) {
            unlink($file);
        }
        rmdir($this->dir.'/releve');
        rmdir($this->dir.'/seals');
        rmdir($this->dir);
    }

    public function testAReleveWithoutASealIsNeverDue(): void
    {
        $this->assertFalse($this->verifier('2026-10-16')->isDue($this->releve()));
    }

    public function testASealIsDueUntilChecked(): void
    {
        file_put_contents($this->dir.'/releve/seal.sig', '{"covers":"files"}');
        $verifier = $this->verifier('2026-10-16');
        $this->assertTrue($verifier->isDue($this->releve()));

        $verifier->verify($this->releve());
        $this->assertFalse($verifier->isDue($this->releve()));
        $this->assertTrue($verifier->last($this->releve())['valid'] ?? null);
    }

    public function testANewSealOrAWeekOldCheckIsDueAgain(): void
    {
        file_put_contents($this->dir.'/releve/seal.sig', '{"covers":"files"}');
        $this->verifier('2026-10-16')->verify($this->releve());

        // The fake answers checked_at 2026-10-16 12:00 UTC.
        $this->assertFalse($this->verifier('2026-10-22 12:00')->isDue($this->releve()));
        $this->assertTrue($this->verifier('2026-10-24 12:00')->isDue($this->releve()));

        file_put_contents($this->dir.'/releve/seal.sig', '{"covers":"files","digest":"other"}');
        $this->assertTrue($this->verifier('2026-10-16 12:00')->isDue($this->releve()));
    }

    private function verifier(string $now): SealVerifier
    {
        return new SealVerifier(
            \dirname(__DIR__).'/fixtures/fake-sablier',
            $this->dir.'/seals',
            \dirname(__DIR__, 2).'/sablier.json',
            new MockClock($now.' UTC'),
        );
    }

    private function releve(): Releve
    {
        return new Releve(9, '2026-10-15', new \DateTimeImmutable('2026-10-16 07:00'), 'Relevé n° 9', '', $this->dir.'/releve');
    }
}
