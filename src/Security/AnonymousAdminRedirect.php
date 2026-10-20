<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A visitor with neither a session nor a remember-me cookie is sent to the
 * login page before the firewall sees the request. Left to the firewall, every
 * such hit would open a session to remember the page asked for: one file per
 * request in var/cache, a disk anyone could fill without logging in.
 *
 * The login page itself opens no session either (its CSRF token is
 * stateless), so an anonymous visitor costs nothing on disk.
 */
final readonly class AnonymousAdminRedirect implements EventSubscriberInterface
{
    /** Reachable before logging in: login, passkey login, logout, second factor. */
    private const string PUBLIC_PATHS = '#^/admin/(?:connexion$|cle/connexion(?:/options)?$|deconnexion$|2fa)#';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32), before the firewall (8).
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!$event->isMainRequest()
            || !str_starts_with($path, '/admin')
            || 1 === preg_match(self::PUBLIC_PATHS, $path)
            || $request->hasPreviousSession()
            || $request->cookies->has('REMEMBERME')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('admin_login')));
    }
}
