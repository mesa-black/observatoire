<?php

namespace App\Controller;

use App\Releve\DataBuilder;
use App\Releve\ReleveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    #[Route('/confidentialite', name: 'privacy', methods: ['GET', 'HEAD'])]
    public function privacy(): Response
    {
        return $this->render('page/privacy.html.twig');
    }

    /** The course: what every relevé's words mean, linked from the top of each. */
    #[Route('/comprendre', name: 'course', methods: ['GET', 'HEAD'])]
    public function course(ReleveRepository $releves, DataBuilder $data): Response
    {
        // How many doors the Observatory measures: counted in the latest
        // published relevé rather than written in the page, where it said 115
        // for a month after the second relevé measured 145.
        $latest = $releves->latestPublished();
        $response = $this->render('page/comprendre.html.twig', [
            'portes' => null !== $latest ? \count($data->doors($latest)) : null,
        ]);
        // Private: it carries a script nonce (the live test), which no shared cache may hand out.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
