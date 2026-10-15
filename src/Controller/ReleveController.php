<?php

namespace App\Controller;

use App\Releve\DataBuilder;
use App\Releve\Releve;
use App\Releve\ReleveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReleveController extends AbstractController
{
    public function __construct(
        private readonly ReleveRepository $releves,
        private readonly DataBuilder $data,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET', 'HEAD'])]
    public function latest(): Response
    {
        $latest = $this->releves->latestPublished();
        if (null !== $latest) {
            return $this->page($latest);
        }
        // Before the first relevé, the address already answers: with the date
        // it opens, and the form to be told. Nothing of the relevé itself.
        $next = $this->releves->nextScheduled() ?? throw new NotFoundHttpException();
        $response = $this->render('releve/bientot.html.twig', ['when' => self::frenchDate($next->publishedAt), 'short' => preg_replace('/ \d{4} à .*$/', '', self::frenchDate($next->publishedAt)), 'at' => $next->publishedAt->format(\DATE_ATOM), 'next' => $next]);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }

    /** A relevé whose time has not come does not exist yet: 404, not 403. */
    #[Route('/releve/{number}', name: 'releve', requirements: ['number' => '[1-9]\d{0,3}'], methods: ['GET', 'HEAD'])]
    public function show(int $number): Response
    {
        return $this->page($this->releves->findPublished($number) ?? throw new NotFoundHttpException());
    }

    private function page(Releve $releve): Response
    {
        $response = $this->render('releve/show.html.twig', ['releve' => $releve, 'donnees' => $this->data->json($releve)]);
        // Short: the page that / shows changes when the next relevé's time comes.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /** "lundi 19 octobre 2026 à 7 h", Paris time, without the intl extension. */
    private static function frenchDate(\DateTimeImmutable $at): string
    {
        $at = $at->setTimezone(new \DateTimeZone('Europe/Paris'));
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $minutes = (int) $at->format('i');

        return \sprintf('%s %d %s %d à %d h%s', $days[(int) $at->format('w')], (int) $at->format('j'),
            $months[(int) $at->format('n') - 1], (int) $at->format('Y'), (int) $at->format('G'), $minutes ? \sprintf(' %02d', $minutes) : '');
    }
}
