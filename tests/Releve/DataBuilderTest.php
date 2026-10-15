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
