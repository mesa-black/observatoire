<?php

namespace App\Newsletter;

use App\Entity\Newsletter;
use App\Entity\Subscriber;
use League\CommonMark\CommonMarkConverter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Builds the two kinds of mail the site sends. Plain, no tracking pixel, no
 * rewritten links: what the reader receives is what was written.
 */
final readonly class Mails
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private Tokens $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function confirmation(Subscriber $subscriber): Email
    {
        $expires = $this->clock->now()->getTimestamp() + Tokens::CONFIRM_TTL;
        $context = [
            'url' => $this->urls->generate('newsletter_confirm', [
                'id' => $subscriber->getId(),
                'expires' => $expires,
                'token' => $this->tokens->confirm($subscriber, $expires),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return (new Email())
            ->to($subscriber->getEmail())
            ->subject('Confirmez votre inscription à l\'Observatoire post-quantique')
            ->text($this->twig->render('email/confirmation.txt.twig', $context))
            ->html($this->twig->render('email/confirmation.html.twig', $context));
    }

    /**
     * One letter for one reader. $subscriber is null for the admin's test
     * copy: its unsubscribe link then leads to "not subscribed", harmlessly.
     */
    public function newsletter(Newsletter $newsletter, string $to, ?Subscriber $subscriber): Email
    {
        $unsubscribe = $this->urls->generate('newsletter_unsubscribe', [
            'id' => $subscriber?->getId() ?? 0,
            'token' => null !== $subscriber ? $this->tokens->unsubscribe($subscriber) : 'test',
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $context = [
            'subject' => $newsletter->getSubject(),
            'body_html' => $this->render($newsletter->getBody()),
            'body_text' => $newsletter->getBody(),
            'unsubscribe' => $unsubscribe,
        ];

        $email = (new Email())
            ->to($to)
            ->subject($newsletter->getSubject())
            ->text($this->twig->render('email/newsletter.txt.twig', $context))
            ->html($this->twig->render('email/newsletter.html.twig', $context));
        // RFC 2369 and RFC 8058: the mail client's own "unsubscribe" button,
        // which POSTs to the link without opening it.
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $email;
    }

    /** Markdown written by the admin. Raw HTML in it is escaped, javascript: links dropped. */
    public function render(string $markdown): string
    {
        return (string) (new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown);
    }
}
