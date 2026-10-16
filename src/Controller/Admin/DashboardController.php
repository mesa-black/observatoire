<?php

namespace App\Controller\Admin;

use App\Entity\Newsletter;
use App\Releve\DataBuilder;
use App\Releve\ReleveRepository;
use App\Repository\NewsletterRepository;
use App\Repository\PasskeyRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_index', methods: ['GET'])]
    public function index(ReleveRepository $releves, SubscriberRepository $subscribers, NewsletterRepository $newsletters, PasskeyRepository $passkeys, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $all = $releves->all();
        $upcoming = array_values(array_filter($all, fn ($r) => $r->isScheduledAt($now)));
        $history = $newsletters->history();

        return $this->render('admin/index.html.twig', [
            'releves' => $all,
            'published' => \count(array_filter($all, fn ($r) => $r->isPublishedAt($now))),
            'next' => $upcoming[0] ?? null,
            'counts' => $subscribers->countByStatus(),
            'newsletters' => $history,
            'sent' => \count(array_filter($history, fn ($n) => Newsletter::SENT === $n->getStatus())),
            'passkeys' => $passkeys->all(),
        ]);
    }

    #[Route('/admin/releves', name: 'admin_releves', methods: ['GET'])]
    public function releves(ReleveRepository $releves, ClockInterface $clock, string $probesDir): Response
    {
        return $this->render('admin/releves.html.twig', [
            'now' => $clock->now(),
            'releves' => array_reverse($releves->all(), true),
            // Optional monthly probe runs (probe CronJob), newest first: for
            // review only, a relevé is still made by hand and committed.
            'probes' => array_reverse(array_map('basename', glob($probesDir.'/*', \GLOB_ONLYDIR) ?: [])),
        ]);
    }

    #[Route('/admin/securite', name: 'admin_security', methods: ['GET'])]
    public function security(PasskeyRepository $passkeys): Response
    {
        return $this->render('admin/security.html.twig', ['passkeys' => $passkeys->all()]);
    }

    /** Any relevé, published or not, exactly as the public will see it. */
    #[Route('/admin/releve/{number}', name: 'admin_releve_preview', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function preview(int $number, ReleveRepository $releves, DataBuilder $data): Response
    {
        $releve = $releves->find($number) ?? throw new NotFoundHttpException();
        $response = $this->render('releve/show.html.twig', ['releve' => $releve, 'donnees' => $data->json($releve), 'is_home' => false]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
