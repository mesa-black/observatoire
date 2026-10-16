<?php

namespace App\Security\Passkey;

use App\Security\AdminUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Logs the admin in from a passkey assertion (POST /admin/cle/connexion, the
 * JSON of navigator.credentials.get()). Answers JSON; the page follows
 * "redirect". Remembered 30 days like a password login.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    public const string LOGIN_PATH = '/admin/cle/connexion';

    public function __construct(private readonly PasskeyManager $passkeys, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::LOGIN_PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        // Another site cannot post here on the admin's behalf: the browser says where from.
        if (!\in_array($request->headers->get('Sec-Fetch-Site', 'same-origin'), ['same-origin'], true)) {
            throw new CustomUserMessageAuthenticationException('Refusé.');
        }
        try {
            $this->passkeys->authenticate((string) $request->getContent(), $request);
        } catch (PasskeyException $e) {
            throw new CustomUserMessageAuthenticationException('Clé d\'accès refusée.', [], 0, $e);
        }

        return new SelfValidatingPassport(new UserBadge(AdminUser::USERNAME), [(new RememberMeBadge())->enable()]);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new PasskeyToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new JsonResponse(['redirect' => $this->urls->generate('admin_index')]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'Clé d\'accès refusée.'], Response::HTTP_UNAUTHORIZED);
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
