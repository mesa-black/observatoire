<?php

namespace App\Command;

use App\Releve\PreviewLinks;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:apercu', 'Prints the secret preview link of a relevé not yet published')]
final readonly class PreviewLinkCommand
{
    public function __construct(private PreviewLinks $links, #[Autowire('%env(SITE_URL)%')] private string $siteUrl)
    {
    }

    public function __invoke(OutputInterface $output, #[Argument('The relevé number')] int $number): int
    {
        $output->writeln(\sprintf('%s/apercu/%d/%s', $this->siteUrl, $number, $this->links->token($number)));

        return 0;
    }
}
