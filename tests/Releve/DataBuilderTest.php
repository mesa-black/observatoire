<?php

namespace App\Tests\Releve;

use App\Releve\DataBuilder;
use App\Releve\Releve;
use PHPUnit\Framework\TestCase;

final class DataBuilderTest extends TestCase
{
    /**
     * Relevé 1 as the prototype's build.sh made it (observatoire-pq/site/
     * data-2026-10-15.js, copied to tests/fixtures): the PHP port must give
     * the same data, key order included, from the files in releves/1.
     */
    public function testSameDataAsBuildShForReleve1(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-15.js');
        $this->assertStringStartsWith('window.OBSERVATOIRE = ', $js);
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(1, '2026-10-15', new \DateTimeImmutable(), 'Relevé n° 1', '', \dirname(__DIR__, 2).'/releves/1');
        $built = (new DataBuilder())->build($releve);

        // Encoded the same way on both sides: compares values, types and key order.
        $this->assertSame($this->encode($expected), $this->encode($built));
        $this->assertCount(115, array_merge(...array_map(fn ($g) => $g->entries, $built->groups)));
    }

    /** Relevé 2 adds the AI group, ai.sh, hf.sh and mail.sh: same parity. */
    public function testSameDataAsBuildShForReleve2(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-16.js');
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(2, '2026-10-16', new \DateTimeImmutable(), 'Relevé n° 2', '', \dirname(__DIR__, 2).'/releves/2');
        $built = (new DataBuilder())->build($releve);

        $this->assertSame($this->encode($expected), $this->encode($built));
        $this->assertSame('ia', $built->meta->focus);
    }

    /**
     * Relevé 3 adds the exchangers' TLS (mailtls.jsonl), joined by MX name.
     * The prototype builds it as a variant (build.sh 2026-10-16 r3) with its
     * draft meta; the meta aside, same data.
     */
    public function testSameDataAsBuildShForReleve3(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-16-r3.js');
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(3, '2026-10-16', new \DateTimeImmutable(), 'Relevé n° 3', '', \dirname(__DIR__, 2).'/releves/3');
        $built = (new DataBuilder())->build($releve);
        $this->assertSame('courriel', $built->meta->focus);
        $expected->meta = $built->meta;

        $this->assertSame($this->encode($expected), $this->encode($built));
        $tls = array_filter(array_map(fn ($e) => $e->mail->tls ?? null, array_merge(...array_map(fn ($g) => $g->entries, $built->groups))));
        $this->assertCount(31, array_filter($tls, fn ($t) => $t->hybrid));
    }

    /**
     * Relevé 4 adds who holds the key of each door (cle.jsonl), per host. The
     * prototype builds it as a variant (build.sh 2026-10-16 r4) with its draft
     * meta; the meta aside, same data.
     */
    public function testSameDataAsBuildShForReleve4(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-16-r4.js');
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(4, '2026-10-16', new \DateTimeImmutable(), 'Relevé n° 4', '', \dirname(__DIR__, 2).'/releves/4');
        $built = (new DataBuilder())->build($releve);
        $this->assertSame('cle', $built->meta->focus);
        $expected->meta = $built->meta;

        $this->assertSame($this->encode($expected), $this->encode($built));
        $kinds = array_map(fn ($e) => $e->cle->kind ?? null, array_merge(...array_map(fn ($g) => $g->entries, $built->groups)));
        $this->assertNotContains(null, $kinds, 'every door has a holder');
    }

    /**
     * Relevé 5 adds a group of its own (« rencontres », the dating apps) to
     * the same files; the prototype builds it as a variant (build.sh
     * 2026-10-19 r5). The meta aside, same data.
     */
    public function testSameDataAsBuildShForReleve5(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-19-r5.js');
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(5, '2026-10-19', new \DateTimeImmutable(), 'Relevé n° 5', '', \dirname(__DIR__, 2).'/releves/5');
        $built = (new DataBuilder())->build($releve);
        $this->assertSame('rencontres', $built->meta->focus);
        $expected->meta = $built->meta;

        $this->assertSame($this->encode($expected), $this->encode($built));
        $groups = array_map(fn ($g) => $g->key, $built->groups);
        $this->assertContains('rencontres', $groups);
    }

    /**
     * Relevé 6 joins the third parties each home page loads (tiers.sh) on the
     * same measurement; the prototype builds it as a variant (build.sh
     * 2026-10-19 r6).
     */
    public function testSameDataAsBuildShForReleve6(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../fixtures/data-2026-10-19-r6.js');
        $expected = json_decode(substr(rtrim($js, ";\n"), \strlen('window.OBSERVATOIRE = ')), false, 512, \JSON_THROW_ON_ERROR);

        $releve = new Releve(6, '2026-10-19', new \DateTimeImmutable(), 'Relevé n° 6', '', \dirname(__DIR__, 2).'/releves/6');
        $built = (new DataBuilder())->build($releve);
        $this->assertSame('oeufs', $built->meta->focus);

        $this->assertSame($this->encode($expected), $this->encode($built));
        $tiers = array_merge(...array_map(fn ($g) => array_map(fn ($e) => \count($e->tiers ?? []), $g->entries), $built->groups));
        $this->assertGreaterThan(0, array_sum($tiers), 'some pages load third parties');
    }

    public function testPartialCostLinesAreSkippedAndUnknownHostsUnreachable(): void
    {
        $releve = new Releve(1, '2020-01-01', new \DateTimeImmutable(), 'T', '', __DIR__.'/../fixtures/releves/1');
        $data = (new DataBuilder())->build($releve);

        [$measured, $absent] = $data->groups[0]->entries;
        $this->assertEquals((object) ['hybrid' => 1.5], $measured->cost);
        $this->assertSame(['name', 'host', 'reachable', 'pq', 'hits', 'tries', 'cost', 'page'], array_keys(get_object_vars($measured)));
        $this->assertEquals((object) ['name' => 'Absent', 'host' => 'absent.example', 'reachable' => false], $absent);
        // No meta file: an empty object, as jq's `$m[0] // {}`.
        $this->assertSame('{}', json_encode($data->meta));
        $this->assertNull($data->self->cost);
    }

    public function testDataCannotCloseItsScriptElement(): void
    {
        $releve = new Releve(1, '2020-01-01', new \DateTimeImmutable(), 'T', '', __DIR__.'/../fixtures/releves/1');
        $json = (new DataBuilder())->json($releve);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringContainsString('\\u003C/script\\u003E', $json);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }
}
