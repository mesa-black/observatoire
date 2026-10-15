<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a new TOTP secret for ADMIN_TOTP_SECRET and the otpauth:// URI an
 * authenticator app (or a password manager) takes by hand. No QR code: it
 * would need a library, and pasting the secret works everywhere.
 */
#[AsCommand('app:admin:totp', 'Generate a TOTP secret for the admin account')]
final class AdminTotpCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= $alphabet[\ord($byte) & 31];
        }
        $io->writeln('ADMIN_TOTP_SECRET='.$secret);
        $io->writeln('otpauth://totp/Observatoire%20post-quantique:admin?secret='.$secret.'&issuer=Observatoire%20post-quantique&algorithm=SHA1&digits=6&period=30');
        $io->note('Store it in the Secret `observatoire`, never in Git; restart the pod.');

        return Command::SUCCESS;
    }
}
