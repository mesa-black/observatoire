<?php

namespace App\Controller;

use App\Releve\ReleveRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The published relevés, and nothing that is not out yet. */
final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET', 'HEAD'])]
    public function __invoke(ReleveRepository $releves, ClockInterface $clock, #[Autowire('%env(SITE_URL)%')] string $siteUrl): Response
    {
        $now = $clock->now();
        $urls = [];
        $latest = null;
        foreach ($releves->all() as $releve) {
            if (!$releve->isPublishedAt($now)) {
                continue;
            }
            $latest = $releve;
            $urls[] = [$siteUrl.$this->generateUrl('releve', ['number' => $releve->number]), $releve->publishedAt];
        }
        if (null !== $latest) {
            array_unshift($urls, [$siteUrl.'/', $latest->publishedAt]);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $at]) {
            $xml .= sprintf("  <url><loc>%s</loc><lastmod>%s</lastmod></url>\n", htmlspecialchars($loc, \ENT_XML1), $at->format('Y-m-d'));
        }
        $xml .= "</urlset>\n";
        $response = new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
