<?php

namespace App\Controller\Admin;

use App\Releve\DataBuilder;
use App\Releve\ReleveRepository;
use App\Repository\NewsletterRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_index', methods: ['GET'])]
    public function index(ReleveRepository $releves, SubscriberRepository $subscribers, NewsletterRepository $newsletters, ClockInterface $clock, string $probesDir): Response
    {
        return $this->render('admin/index.html.twig', [
            'now' => $clock->now(),
            'releves' => array_reverse($releves->all(), true),
            'counts' => $subscribers->countByStatus(),
            'newsletters' => $newsletters->history(),
            // Optional monthly probe runs (probe CronJob), newest first: for
            // review only, a relevé is still made by hand and committed.
            'probes' => array_reverse(array_map('basename', glob($probesDir.'/*', \GLOB_ONLYDIR) ?: [])),
        ]);
    }

    /** Any relevé, published or not, exactly as the public will see it. */
    #[Route('/admin/releve/{number}', name: 'admin_releve_preview', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function preview(int $number, ReleveRepository $releves, DataBuilder $data): Response
    {
        $releve = $releves->find($number) ?? throw new NotFoundHttpException();
        $response = $this->render('releve/show.html.twig', ['releve' => $releve, 'donnees' => $data->json($releve)]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
