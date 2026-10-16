<?php

namespace App\Controller\Admin;

use App\Entity\Newsletter;
use App\Newsletter\Mails;
use App\Newsletter\Sender;
use App\Releve\Releve;
use App\Releve\ReleveRepository;
use App\Repository\NewsletterRepository;
use App\Repository\SubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    #[Route('', name: 'admin_newsletter_index', methods: ['GET'])]
    public function index(NewsletterRepository $newsletters, SubscriberRepository $subscribers): Response
    {
        return $this->render('admin/newsletter_index.html.twig', [
            'newsletters' => $newsletters->history(),
            'confirmed' => $subscribers->countByStatus()['confirmed'],
        ]);
    }

    #[Route('/nouvelle', name: 'admin_newsletter_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Mails $mails, SubscriberRepository $subscribers): Response
    {
        return $this->edit($request, null, $mails, $subscribers);
    }

    /**
     * Write and, in the same form, say when it leaves: kept as a draft, now,
     * at a date and time, or with a relevé. "Mettre à jour l'aperçu" re-renders
     * the page with the preview, for a browser without the script.
     */
    #[Route('/{id}/modifier', name: 'admin_newsletter_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ?Newsletter $newsletter, Mails $mails, SubscriberRepository $subscribers): Response
    {
        if (null !== $newsletter && !$newsletter->isEditable()) {
            return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
        }
        $subject = $newsletter?->getSubject() ?? '';
        $body = $newsletter?->getBody() ?? '';
        $when = 'draft';
        $atDate = '';
        $atTime = '09:00';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'newsletter');
            $subject = trim((string) $request->request->get('subject'));
            $body = str_replace("\r\n", "\n", (string) $request->request->get('body'));
            $when = (string) $request->request->get('when', 'draft');
            $atDate = (string) $request->request->get('at_date');
            $atTime = (string) $request->request->get('at_time', '09:00');
            if ('preview' === $request->request->get('_action')) {
                // Nothing saved: the same page again, with the preview.
            } elseif ('' === $subject || mb_strlen($subject) > 200 || '' === trim($body)) {
                $error = 'Un objet (200 caractères au plus) et un texte sont nécessaires.';
            } else {
                $moment = 'draft' === $when ? null : $this->moment($request);
                if (\is_string($moment)) {
                    $error = $moment;
                } else {
                    if (null === $newsletter) {
                        $newsletter = new Newsletter($subject, $body, $this->clock->now());
                        $this->em->persist($newsletter);
                    } else {
                        $newsletter->edit($subject, $body);
                    }
                    if (null !== $moment) {
                        $newsletter->schedule(...$moment);
                    }
                    $this->em->flush();
                    $this->addFlash('ok', null === $moment ? 'Brouillon enregistré.' : 'Lettre enregistrée et programmée. Elle part dans la minute qui suit l\'heure prévue.');

                    return $this->redirectToRoute('admin_newsletter_show', ['id' => $newsletter->getId()]);
                }
            }
        }

        return $this->render('admin/newsletter_edit.html.twig', [
            'newsletter' => $newsletter,
            'subject' => $subject,
            'body' => $body,
            'preview' => '' !== trim($body) ? $mails->render($body) : '',
            'error' => $error,
            'when' => $when,
            'at_date' => $atDate,
            'at_time' => $atTime,
            'confirmed' => $subscribers->countByStatus()['confirmed'],
            'upcoming' => $this->upcoming(),
        ]);
    }

    /** The edit page's live preview: Markdown in, the mail's HTML out. Saves nothing. */
    #[Route('/apercu', name: 'admin_newsletter_preview', methods: ['POST'])]
    public function preview(Request $request, Mails $mails): JsonResponse
    {
        if ('same-origin' !== $request->headers->get('Sec-Fetch-Site', 'same-origin')) {
            throw $this->createAccessDeniedException();
        }
        $payload = json_decode((string) $request->getContent(), true);
        $body = \is_array($payload) && \is_string($payload['body'] ?? null) ? $payload['body'] : '';

        return new JsonResponse(['html' => $mails->render(mb_substr($body, 0, 100_000))]);
    }

    #[Route('/{id}', name: 'admin_newsletter_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Newsletter $newsletter, Mails $mails, SubscriberRepository $subscribers): Response
    {
        return $this->render('admin/newsletter_show.html.twig', [
            'newsletter' => $newsletter,
            'html' => $mails->render($newsletter->getBody()),
            'confirmed' => $subscribers->countByStatus()['confirmed'],
            'upcoming' => $this->upcoming(),
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
        $moment = $this->moment($request);
        if (\is_string($moment)) {
            $this->addFlash('erreur', $moment);
        } else {
            $newsletter->schedule(...$moment);
            $this->em->flush();
            $this->addFlash('ok', 'Lettre programmée. Elle part dans la minute qui suit l\'heure prévue.');
        }

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

    /**
     * The moment chosen in the form, in UTC, and the relevé it waits for;
     * or why it cannot be used.
     *
     * @return array{\DateTimeImmutable, ?int}|string
     */
    private function moment(Request $request): array|string
    {
        $when = (string) $request->request->get('when');
        $utc = new \DateTimeZone('UTC');
        if ('now' === $when) {
            return [$this->clock->now()->setTimezone($utc), null];
        }
        if (str_starts_with($when, 'releve:')) {
            $releve = $this->releves->find((int) substr($when, 7));

            return null !== $releve ? [$releve->publishedAt->setTimezone($utc), $releve->number] : 'Ce relevé n\'existe pas.';
        }
        if ('at' === $when) {
            // <input type="date"> and <input type="time">: Paris wall-clock time.
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $request->request->get('at_date').' '.$request->request->get('at_time'), new \DateTimeZone(ReleveRepository::TIMEZONE));
            if (false === $at) {
                return 'Indiquez une date et une heure d\'envoi.';
            }
            if ($at < $this->clock->now()) {
                return 'Cette date est passée : choisissez « Maintenant » ou une date à venir.';
            }

            return [$at->setTimezone($utc), null];
        }

        return 'Choisissez quand la lettre part.';
    }

    /** @return list<Releve> relevés still to come: a letter can wait for one (not for a draft) */
    private function upcoming(): array
    {
        $now = $this->clock->now();

        return array_values(array_filter($this->releves->all(), fn (Releve $r) => $r->isScheduledAt($now)));
    }

    private function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
