<?php

namespace App\Controller;

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
    public function course(): Response
    {
        $response = $this->render('page/comprendre.html.twig');
        // Private: it carries a script nonce (the live test), which no shared cache may hand out.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
