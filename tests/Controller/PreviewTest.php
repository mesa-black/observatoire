<?php

namespace App\Tests\Controller;

use App\Releve\PreviewLinks;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreviewTest extends WebTestCase
{
    public function testTheSignedLinkOpensAReleveBeforeItsTimeAndNothingElseDoes(): void
    {
        $client = static::createClient();
        /** @var PreviewLinks $links */
        $links = static::getContainer()->get(PreviewLinks::class);

        $client->request('GET', '/apercu/2/'.$links->token(2));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('title', 'Relevé n° 2');
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $client->request('GET', '/apercu/2/'.$links->token(1));
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/releve/2');
        $this->assertResponseStatusCodeSame(404);
    }
}
