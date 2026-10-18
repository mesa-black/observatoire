<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Attribute\AsTwigFunction;

/**
 * The policy we recommend to the others, applied here: a script runs only if
 * it carries this response's nonce ('strict-dynamic': what it loads may run
 * too, nothing else). A 'self' allowlist would let an injection load any
 * script served from this origin; a nonce changes with every response.
 *
 * Set on every response PHP answers. The Caddyfile keeps a default for the
 * static files, which it sets only when no policy is there yet.
 */
final class ContentSecurityPolicy implements EventSubscriberInterface
{
    private const string NONCE = '_csp_nonce';

    /** The relevé pages and their previews play a track hotlinked from archive.org (owner's decision, 2026-10-16). */
    private const string MEDIA_PATHS = '#^/(?:$|releve/|admin/releve/|apercu/)#';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** Kept on the request: the worker serves many, each its own. */
    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('A nonce belongs to a request.');
        if (!$request->attributes->has(self::NONCE)) {
            $request->attributes->set(self::NONCE, base64_encode(random_bytes(18)));
        }

        return (string) $request->attributes->get(self::NONCE);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $media = preg_match(self::MEDIA_PATHS, $event->getRequest()->getPathInfo()) ? " media-src https://archive.org https://*.archive.org;" : '';
        $event->getResponse()->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$this->nonce()}' 'strict-dynamic'; object-src 'none'; connect-src 'self' https://handshake.mesa.black; style-src 'self'; font-src 'self'; img-src 'self' data:;{$media} base-uri 'none'; form-action 'self'; frame-ancestors 'none'; require-trusted-types-for 'script'");
    }
}
