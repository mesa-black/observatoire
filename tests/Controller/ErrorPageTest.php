<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ErrorPageTest extends WebTestCase
{
    public function testAnUnknownAddressGetsTheSitesOwnPageInFrench(): void
    {
        // debug off: the error page a visitor sees, not the exception page.
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/nulle-part');
        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('title', 'Page introuvable');
        $this->assertSelectorTextContains('h1', 'trouvé nulle part');
        $this->assertSelectorExists('link[href^="/site.css"]');
        $this->assertSelectorNotExists('style');
    }
}
