<?php

namespace App\Tests\Controller;

use App\Security\AdminCodeStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Password, then the code the app "mailed" (read back from where it waits). */
trait LogsInAdmin
{
    private function logInAdmin(KernelBrowser $client): void
    {
        $client->request('GET', '/admin/connexion');
        $client->submitForm('Se connecter', ['_password' => 'secret']);
        // /admin, then /admin/2fa, where the code is generated and "mailed".
        while ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        if (str_ends_with($client->getRequest()->getPathInfo(), '/admin/2fa')) {
            /** @var AdminCodeStore $codes */
            $codes = static::getContainer()->get(AdminCodeStore::class);
            $client->submitForm('Valider', ['_auth_code' => (string) $codes->get()]);
        }
    }
}
