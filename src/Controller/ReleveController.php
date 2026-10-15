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
        return $this->page($this->releves->latestPublished() ?? throw new NotFoundHttpException());
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
}
