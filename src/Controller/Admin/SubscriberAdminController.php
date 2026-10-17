<?php

namespace App\Controller\Admin;

use App\Entity\Subscriber;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who reads us, and who asked without confirming yet. Deleting is what an
 * erasure request needs (GDPR art. 17), and what unsubscribing already does:
 * the row goes, nothing is kept beside it.
 */
#[Route('/admin/abonnes')]
final class SubscriberAdminController extends AbstractController
{
    #[Route('', name: 'admin_subscriber_index', methods: ['GET'])]
    public function index(SubscriberRepository $subscribers): Response
    {
        return $this->render('admin/subscribers.html.twig', [
            'confirmed' => $subscribers->withStatus(Subscriber::CONFIRMED),
            'pending' => $subscribers->withStatus(Subscriber::PENDING),
            'pending_kept_days' => Subscriber::PENDING_KEPT_DAYS,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_subscriber_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Subscriber $subscriber, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('subscriber-'.$subscriber->getId(), (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
        $email = $subscriber->getEmail();
        $em->remove($subscriber);
        $em->flush();
        $this->addFlash('ok', $email.' supprimé : plus rien n\'est gardé sur cette adresse.');

        return $this->redirectToRoute('admin_subscriber_index');
    }
}
