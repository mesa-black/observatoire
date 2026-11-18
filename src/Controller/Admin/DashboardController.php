<?php

namespace App\Controller\Admin;

use App\Entity\Newsletter;
use App\Releve\DataBuilder;
use App\Releve\ReleveRepository;
use App\Releve\SealVerifier;
use App\Repository\NewsletterRepository;
use App\Repository\PasskeyRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
            // Every scheduled one, not only the first: the one after it is reviewed too.
            'upcoming' => $upcoming,
            // Not upcoming: no date holds for them, but the admin still previews them.
            'drafts' => array_values(array_filter($all, fn ($r) => $r->draft)),
            'counts' => $subscribers->countByStatus(),
            'newsletters' => $history,
            'sent' => \count(array_filter($history, fn ($n) => Newsletter::SENT === $n->getStatus())),
            'passkeys' => $passkeys->all(),
        ]);
    }

    #[Route('/admin/releves', name: 'admin_releves', methods: ['GET'])]
    public function releves(ReleveRepository $releves, SealVerifier $seals, ClockInterface $clock, string $probesDir): Response
    {
        $all = array_reverse($releves->all(), true);
        $sceaux = [];
        foreach ($all as $number => $releve) {
            $sceaux[$number] = ['sealed' => $seals->isSealed($releve), 'checked' => $seals->isChecked($releve), 'last' => $seals->last($releve)];
        }

        return $this->render('admin/releves.html.twig', [
            'now' => $clock->now(),
            'releves' => $all,
            // What Sablier said of each seal the last time it was asked.
            'sceaux' => $sceaux,
            // Optional monthly probe runs (probe CronJob), newest first: for
            // review only, a relevé is still made by hand and committed.
            'probes' => array_reverse(array_map('basename', glob($probesDir.'/*', \GLOB_ONLYDIR) ?: [])),
        ]);
    }

    /**
     * Ask Sablier now, rather than waiting for the daily tick. One relevé, or
     * every seal not checked yet: one already checked is left to the weekly
     * tick. Checking needs only the public keys: resealing is done on the
     * owner's machine (make seal), never here.
     */
    #[Route('/admin/releves/verifier/{number}', name: 'admin_releve_verify', requirements: ['number' => '\d+'], defaults: ['number' => null], methods: ['POST'])]
    public function verify(Request $request, ?int $number, ReleveRepository $releves, SealVerifier $seals): Response
    {
        if (!$this->isCsrfTokenValid('verifier-sceau', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
        if (null !== $number) {
            $one = $releves->find($number) ?? throw new NotFoundHttpException();
            $targets = [$one];
        } else {
            $targets = array_values(array_filter($releves->all(), static fn ($r) => !$seals->isChecked($r)));
        }
        $held = $failed = 0;
        foreach ($targets as $releve) {
            if (!$seals->isSealed($releve)) {
                continue;
            }
            if (true === ($seals->verify($releve)['valid'] ?? false)) {
                ++$held;
            } else {
                ++$failed;
            }
        }
        if (0 === $held + $failed) {
            $this->addFlash('ok', 'Aucun sceau en attente de vérification : chacun est revérifié chaque semaine.');
        } elseif (0 === $failed) {
            $this->addFlash('ok', 1 === $held ? 'Sceau vérifié par Sablier : conforme.' : $held.' sceaux vérifiés par Sablier : tous conformes.');
        } else {
            $this->addFlash('erreur', $failed.' sceau'.($failed > 1 ? 'x' : '').' en échec sur '.($held + $failed).' : voir le détail ci-dessous.');
        }

        return $this->redirectToRoute('admin_releves');
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
