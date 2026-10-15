<?php

namespace App\Controller;

use App\Entity\Subscriber;
use App\Newsletter\Subscriptions;
use App\Newsletter\TooManyRequests;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reader's side of the newsletter. No session, no cookie: every step is
 * carried by the link in the mail.
 *
 * Confirming and unsubscribing are POSTs. A GET only shows the button: mail
 * security scanners open every link they see, and a GET that acted would
 * confirm addresses nobody confirmed, or unsubscribe people who never asked
 * (RFC 8058 says the same of List-Unsubscribe). The mail client's own
 * unsubscribe button POSTs directly: one click.
 */
final class NewsletterController extends AbstractController
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    #[Route('/newsletter', name: 'newsletter_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): Response
    {
        // Another site's form posting here: the browser says so. No CSRF
        // token, which would need a session cookie; this header is enough for
        // a form whose worst effect is a confirmation mail.
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && !\in_array($site, ['same-origin', 'none'], true)) {
            return $this->message('Demande refusée', 'Le formulaire doit être envoyé depuis ce site.', Response::HTTP_FORBIDDEN);
        }
        // The field humans never see (site.css hides it): only robots fill it.
        // They get the same answer as everyone, and no mail is sent.
        if ('' !== (string) $request->request->get('site', '')) {
            return $this->requested();
        }
        $email = Subscriptions::normalize((string) $request->request->get('email', ''));
        if (null === $email) {
            return $this->message('Adresse non valide', 'Cette adresse électronique ne semble pas valide. Revenez à la page pour la corriger.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->subscriptions->request($email, $request->getClientIp());
        } catch (TooManyRequests) {
            return $this->message('Trop de demandes', 'Trop de demandes en peu de temps. Réessayez plus tard.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->requested();
    }

    #[Route('/confirmation/{id}/{expires}/{token}', name: 'newsletter_confirm', requirements: ['id' => '\d+', 'expires' => '\d+', 'token' => '[0-9a-f]{32}'], methods: ['GET', 'POST'])]
    public function confirm(Request $request, int $id, int $expires, string $token): Response
    {
        $subscriber = $this->subscriptions->find($id);
        if (null === $subscriber || !$this->subscriptions->isValidConfirm($subscriber, $expires, $token)) {
            return $this->message('Lien expiré', 'Ce lien n\'est plus valable : il a expiré, ou l\'inscription a été retirée. Vous pouvez refaire la demande depuis la page de l\'Observatoire.', Response::HTTP_NOT_FOUND);
        }
        if ($request->isMethod('POST')) {
            $this->subscriptions->confirm($subscriber);

            return $this->message('Inscription confirmée', 'C\'est fait. Vous recevrez un message à chaque relevé. Chaque message contient un lien pour vous désinscrire.');
        }
        if ($subscriber->isConfirmed()) {
            return $this->message('Inscription confirmée', 'Cette adresse est déjà inscrite.');
        }

        return $this->render('newsletter/action.html.twig', [
            'title' => 'Confirmer l\'inscription',
            'text' => 'Confirmez l\'inscription de '.$subscriber->getEmail().' à la lettre de l\'Observatoire post-quantique.',
            'button' => 'Confirmer l\'inscription',
        ]);
    }

    #[Route('/desinscription/{id}/{token}', name: 'newsletter_unsubscribe', requirements: ['id' => '\d+', 'token' => '[0-9a-z]+'], methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, int $id, string $token): Response
    {
        $subscriber = $this->subscriptions->find($id);
        $valid = $subscriber instanceof Subscriber && $this->subscriptions->isValidUnsubscribe($subscriber, $token);
        if ($request->isMethod('POST')) {
            if ($valid) {
                $this->subscriptions->unsubscribe($subscriber);
            }

            // The same answer either way: an unknown link has nothing left to remove.
            return $this->message('Désinscription faite', 'Cette adresse ne recevra plus rien de l\'Observatoire. Elle a été effacée.');
        }
        if (!$valid) {
            return $this->message('Déjà désinscrit', 'Cette adresse n\'est pas, ou plus, inscrite.');
        }

        return $this->render('newsletter/action.html.twig', [
            'title' => 'Se désinscrire',
            'text' => 'Ne plus recevoir la lettre de l\'Observatoire post-quantique à '.$subscriber->getEmail().'. L\'adresse sera effacée.',
            'button' => 'Me désinscrire',
        ]);
    }

    private function requested(): Response
    {
        return $this->message('Vérifiez votre messagerie', 'Si cette adresse peut être inscrite, un message vient d\'y être envoyé. Cliquez sur le lien qu\'il contient pour confirmer : sans cela, rien n\'est gardé, et la demande est effacée au bout de sept jours.');
    }

    private function message(string $title, string $text, int $status = Response::HTTP_OK): Response
    {
        return $this->render('newsletter/message.html.twig', ['title' => $title, 'text' => $text], new Response(status: $status));
    }
}
