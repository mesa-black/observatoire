<?php

namespace App\Controller\Admin;

use App\Entity\Passkey;
use App\Security\Passkey\PasskeyAuthenticator;
use App\Security\Passkey\PasskeyException;
use App\Security\Passkey\PasskeyManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The admin's passkeys: add one (logged in), remove one; and the login options. */
final class PasskeyController extends AbstractController
{
    public function __construct(private readonly PasskeyManager $passkeys)
    {
    }

    #[Route('/admin/cle/options', name: 'admin_passkey_options', methods: ['POST'])]
    public function options(Request $request): JsonResponse
    {
        $this->sameOrigin($request);

        return new JsonResponse($this->passkeys->creationOptions($request));
    }

    #[Route('/admin/cle', name: 'admin_passkey_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $this->sameOrigin($request);
        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload) || !\is_array($payload['credential'] ?? null)) {
            return new JsonResponse(['error' => 'Réponse invalide.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $passkey = $this->passkeys->register(json_encode($payload['credential'], \JSON_THROW_ON_ERROR), \is_string($payload['name'] ?? null) ? $payload['name'] : '', $request);
        } catch (PasskeyException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->addFlash('ok', \sprintf('Clé d\'accès « %s » ajoutée.', $passkey->getName()));

        return new JsonResponse(['redirect' => $this->generateUrl('admin_index')], Response::HTTP_CREATED);
    }

    #[Route('/admin/cle/{id}/supprimer', name: 'admin_passkey_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Passkey $passkey, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-cle-'.$passkey->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($passkey);
        $em->flush();
        $this->addFlash('ok', \sprintf('Clé d\'accès « %s » supprimée.', $passkey->getName()));

        return $this->redirectToRoute('admin_index');
    }

    /** Public: the login page asks for options before anyone is logged in. */
    #[Route('/admin/cle/connexion/options', name: 'admin_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $this->sameOrigin($request);

        return new JsonResponse($this->passkeys->requestOptions($request));
    }

    /** Handled by PasskeyAuthenticator before any controller; reached only if it did not run. */
    #[Route(PasskeyAuthenticator::LOGIN_PATH, name: 'admin_passkey_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse(['error' => 'Clé d\'accès refusée.'], Response::HTTP_UNAUTHORIZED);
    }

    private function sameOrigin(Request $request): void
    {
        if ('same-origin' !== $request->headers->get('Sec-Fetch-Site', 'same-origin')) {
            throw $this->createAccessDeniedException();
        }
    }
}
