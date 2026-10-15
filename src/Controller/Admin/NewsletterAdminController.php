<?php

namespace App\Controller\Admin;

use App\Entity\Newsletter;
use App\Newsletter\Mails;
use App\Newsletter\Sender;
use App\Releve\ReleveRepository;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Write, test, schedule. Sending itself is the consumer's job (App\Newsletter\
 * Sender, once a minute): "send now" means "scheduled for now".
 */
#[Route('/admin/lettres')]
final class NewsletterAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly ReleveRepository $releves,
    ) {
    }

    #[Route('/nouvelle', name: 'admin_newsletter_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->edit($request, null);
    }

    #[Route('/{id}/modifier', name: 'admin_newsletter_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ?Newsletter $newsletter): Response
    {
        if (null !== $newsletter && !$newsletter->isEditable()) {
            return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
        }
        $subject = $newsletter?->getSubject() ?? '';
        $body = $newsletter?->getBody() ?? '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'newsletter');
            $subject = trim((string) $request->request->get('subject'));
            $body = str_replace("\r\n", "\n", (string) $request->request->get('body'));
            if ('' === $subject || mb_strlen($subject) > 200 || '' === trim($body)) {
                $error = 'Un objet (200 caractères au plus) et un texte sont nécessaires.';
            } else {
                if (null === $newsletter) {
                    $newsletter = new Newsletter($subject, $body, $this->clock->now());
                    $this->em->persist($newsletter);
                } else {
                    $newsletter->edit($subject, $body);
                }
                $this->em->flush();

                return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
            }
        }

        return $this->render('admin/newsletter_edit.html.twig', [
            'newsletter' => $newsletter,
            'subject' => $subject,
            'body' => $body,
            'error' => $error,
        ]);
    }

    #[Route('/{id}', name: 'admin_newsletter_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Newsletter $newsletter, Mails $mails, SubscriberRepository $subscribers): Response
    {
        $now = $this->clock->now();

        return $this->render('admin/newsletter_show.html.twig', [
            'newsletter' => $newsletter,
            'html' => $mails->render($newsletter->getBody()),
            'confirmed' => $subscribers->countByStatus()['confirmed'],
            // Letters can wait for a relevé that is not out yet.
            'upcoming' => array_filter($this->releves->all(), fn ($r) => !$r->isPublishedAt($now)),
        ]);
    }

    #[Route('/{id}/test', name: 'admin_newsletter_test', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function test(Request $request, Newsletter $newsletter, Sender $sender, string $adminEmail): Response
    {
        $this->checkCsrf($request, 'newsletter-'.$newsletter->getId());
        $sender->sendTest($newsletter);
        $this->addFlash('ok', 'Test envoyé à '.$adminEmail.'.');

        return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
    }

    #[Route('/{id}/programmer', name: 'admin_newsletter_schedule', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function schedule(Request $request, Newsletter $newsletter): Response
    {
        $this->checkCsrf($request, 'newsletter-'.$newsletter->getId());
        $when = (string) $request->request->get('when');
        $releveNumber = null;
        $paris = new \DateTimeZone(ReleveRepository::TIMEZONE);
        if ('now' === $when) {
            $at = $this->clock->now();
        } elseif (str_starts_with($when, 'releve:')) {
            $releve = $this->releves->find((int) substr($when, 7)) ?? throw new BadRequestHttpException();
            $at = $releve->publishedAt;
            $releveNumber = $releve->number;
        } else {
            // <input type="datetime-local">: Paris wall-clock time.
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) $request->request->get('at'), $paris)
                ?: throw new BadRequestHttpException();
        }
        $newsletter->schedule($at->setTimezone(new \DateTimeZone('UTC')), $releveNumber);
        $this->em->flush();
        $this->addFlash('ok', 'Lettre programmée. Elle part dans la minute qui suit l\'heure prévue.');

        return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
    }

    #[Route('/{id}/annuler', name: 'admin_newsletter_unschedule', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unschedule(Request $request, Newsletter $newsletter): Response
    {
        $this->checkCsrf($request, 'newsletter-'.$newsletter->getId());
        $newsletter->unschedule();
        $this->em->flush();
        $this->addFlash('ok', 'Programmation annulée : la lettre est redevenue un brouillon.');

        return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
    }

    private function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
