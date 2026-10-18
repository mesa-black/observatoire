<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentSecurityPolicyTest extends WebTestCase
{
    use LogsInAdmin;

    public function testTheRelevePageRunsOnlyTheScriptCarryingThisResponsesNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/releve/1');
        $this->assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("#script-src 'nonce-[A-Za-z0-9+/]{24}' 'strict-dynamic';#", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // handshake.mesa.black tells the reader which key exchange their browser negotiated.
        $this->assertStringContainsString("connect-src 'self' https://handshake.mesa.black;", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        $this->assertStringContainsString('media-src https://archive.org', $csp);
        $this->assertStringNotContainsString("'self' 'strict-dynamic'", $csp);

        $nonce = $this->nonceOf($csp);
        $this->assertSelectorExists(\sprintf('script[src^="/app.js"][nonce="%s"]', $nonce));
        // No shared cache may hand the same nonce to everyone.
        $this->assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/releve/1');
        $this->assertStringNotContainsString($nonce, (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testAdminScriptsCarryTheNonceAndTheMusicStaysOnTheRelevePages(): void
    {
        $client = static::createClient();
        // The dashboard reads the letters: a schema, even when this test runs first.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->logInAdmin($client);
        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('media-src', $csp);
        $nonce = $this->nonceOf($csp);
        $this->assertSelectorExists(\sprintf('script[src^="/cle-acces.js"][nonce="%s"]', $nonce));
        $this->assertSelectorExists(\sprintf('script[src^="/admin.js"][nonce="%s"]', $nonce));
        $this->assertSelectorNotExists('script[src]:not([nonce])');
    }

    private function nonceOf(string $csp): string
    {
        if (1 !== preg_match("#'nonce-([^']+)'#", $csp, $m)) {
            $this->fail("No nonce in: $csp");
        }

        return $m[1];
    }
}
