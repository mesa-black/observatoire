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
}
