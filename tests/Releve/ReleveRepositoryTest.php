<?php

namespace App\Tests\Releve;

use App\Releve\Releve;
use App\Releve\ReleveRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReleveRepositoryTest extends TestCase
{
    private const string DIR = __DIR__.'/../fixtures/releves';

    public function testADraftIsNeverPublishedNorScheduledWhateverItsDate(): void
    {
        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $past = new Releve(3, '2026-10-16', $now->modify('-1 day'), 'T', '', '/x', draft: true);
        $future = new Releve(3, '2026-10-16', $now->modify('+1 day'), 'T', '', '/x', draft: true);

        $this->assertFalse($past->isPublishedAt($now));
        $this->assertFalse($future->isPublishedAt($now));
        $this->assertFalse($future->isScheduledAt($now), 'a letter cannot wait for it');
        $this->assertTrue((new Releve(3, '2026-10-16', $now->modify('+1 day'), 'T', '', '/x'))->isScheduledAt($now));
    }

    public function testAReleveIsMeasurementsOrTheCourseAndNothingElse(): void
    {
        $releves = new ReleveRepository(self::DIR, new MockClock('2026-10-16 12:00:00', 'UTC'));
        $this->assertFalse($releves->find(1)?->isCourse());
        $this->assertTrue($releves->find(4)?->isCourse());

        $dir = sys_get_temp_dir().'/releves-'.bin2hex(random_bytes(4));
        mkdir($dir.'/7', 0o777, true);
        file_put_contents($dir.'/7/meta.json', '{"number": 7, "date": "2020-01-01", "published": "2020-01-15 07:00", "page": "video"}');
        $this->expectException(\UnexpectedValueException::class);
        (new ReleveRepository($dir, new MockClock()))->all();
    }

    public function testOnlyRelevesWhoseTimeHasComeArePublished(): void
    {
        $releves = new ReleveRepository(self::DIR, new MockClock('2026-10-16 12:00:00', 'UTC'));

        $this->assertSame(1, $releves->latestPublished()?->number);
        $this->assertNotNull($releves->findPublished(1));
        $this->assertNull($releves->findPublished(2), 'a relevé still to come');
        $this->assertNotNull($releves->find(2), 'but the admin sees it');
        $this->assertNull($releves->findPublished(3));
    }

    public function testPublicationTimeIsParisTime(): void
    {
        // Relevé 1: "2020-01-15 07:00" in Paris is 06:00 UTC in winter.
        $before = new ReleveRepository(self::DIR, new MockClock('2020-01-15 05:59:59', 'UTC'));
        $after = new ReleveRepository(self::DIR, new MockClock('2020-01-15 06:00:00', 'UTC'));

        $this->assertNull($before->latestPublished());
        $this->assertSame(1, $after->latestPublished()?->number);
    }

    public function testTheRealRelevesAreReadable(): void
    {
        $releves = new ReleveRepository(\dirname(__DIR__, 2).'/releves', new MockClock());

        $this->assertNotEmpty($releves->all());
        foreach ($releves->all() as $n => $releve) {
            $this->assertSame($n, $releve->number);
            // The course (relevé 5) has no measurements; every other relevé has its files.
            if (!$releve->isCourse()) {
                $this->assertFileExists($releve->dir.'/'.$releve->date.'.jsonl');
            }
        }
    }
}
