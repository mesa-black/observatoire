<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeoTest extends WebTestCase
{
    public function testTheReleveCarriesItsPreviewAndStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/releve/1');
        $this->assertResponseIsSuccessful();
        $this->assertSame('http://localhost/releve/1', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertSame('http://localhost/cards/releve-1.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        $this->assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        $ld = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, 16, \JSON_THROW_ON_ERROR);
        $this->assertSame('Dataset', $ld['@type']);
        $this->assertSame('2020-01-15', $ld['datePublished']);
    }

    public function testTheSitemapListsOnlyWhatIsOut(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');
        $this->assertResponseIsSuccessful();
        $xml = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<loc>http://localhost/releve/1</loc>', $xml);
        $this->assertStringNotContainsString('/releve/2', $xml);
    }
}
