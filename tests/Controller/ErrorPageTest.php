<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class ErrorPageTest extends KernelTestCase
{
    /**
     * The page a visitor gets in prod. Rendered through Twig rather than by a
     * request with debug off: that kernel would share the test cache directory
     * with the debug one, and the other tests would inherit its container.
     */
    public function testAnUnknownAddressGetsTheSitesOwnPageInFrench(): void
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $page = new Crawler($twig->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']));

        $this->assertStringContainsString('Page introuvable', $page->filter('title')->text());
        $this->assertStringContainsString('trouvé nulle part', $page->filter('h1')->text());
        $this->assertCount(1, $page->filter('link[href^="/site.css"]'));
        // The CSP refuses inline styles: Symfony's default page has one.
        $this->assertCount(0, $page->filter('style'));
    }
}
