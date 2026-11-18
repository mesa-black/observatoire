<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The seal column of the relevés: what Sablier said, and asking it again.
 * Sablier itself is stood in for by tests/fixtures/fake-sablier.
 */
final class SealAdminTest extends WebTestCase
{
    use LogsInAdmin;

    protected function setUp(): void
    {
        putenv('FAKE_SABLIER_BROKEN');
        $dir = \dirname(__DIR__, 2).'/var/test/seals';
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    protected function tearDown(): void
    {
        putenv('FAKE_SABLIER_BROKEN');
        parent::tearDown();
    }

    public function testEachReleveSaysWhetherItIsSealed(): void
    {
        $client = $this->loggedIn();
        $client->request('GET', '/admin/releves');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('tbody', 'make seal N=1');
        $this->assertSelectorTextContains('tbody', 'scellé, jamais vérifié');
        // A draft is not asked for a seal yet: it can still change.
        $this->assertSelectorTextContains('#releve-3 td.sceau', 'À sceller une fois publié');
        $this->assertSelectorTextNotContains('#releve-3 td.sceau', 'make seal');
        // A button only where there is a seal to check: relevé 2 here.
        $this->assertCount(1, $client->getCrawler()->filter('form[action="/admin/releves/verifier/2"]'));
        $this->assertCount(0, $client->getCrawler()->filter('form[action="/admin/releves/verifier/1"]'));
        // The shield beside the title of a sealed relevé, and only there.
        $this->assertSelectorExists('#releve-2 .scelle');
        $this->assertSelectorNotExists('#releve-1 .scelle');
    }

    public function testCheckingASealShowsWhenItWasSealedAndWhenChecked(): void
    {
        $client = $this->loggedIn();
        $client->request('GET', '/admin/releves');
        $client->submit($client->getCrawler()->filter('form[action="/admin/releves/verifier/2"]')->form());

        $this->assertResponseRedirects('/admin/releves');
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'conforme');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'Scellé le 15/10/2026 10:00');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'Ed25519 + ML-DSA-65');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'par DigiCert');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'Ancré au bloc 976246');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'Vérifié le 16/10/2026 14:00 par Sablier 0.14.0');
        // Found sound: no button of its own any more, the shield stays.
        $this->assertCount(0, $client->getCrawler()->filter('form[action="/admin/releves/verifier/2"]'));
        $this->assertSelectorExists('#releve-2 .scelle');
    }

    public function testAFailedCheckSaysWhatFailed(): void
    {
        putenv('FAKE_SABLIER_BROKEN=1');
        $client = $this->loggedIn();
        $client->request('GET', '/admin/releves');
        $client->submit($client->getCrawler()->filter('form[action="/admin/releves/verifier"]')->form());

        $client->followRedirect();
        $this->assertSelectorTextContains('.erreur', '1 sceau en échec sur 1');
        $this->assertSelectorTextContains('#releve-2 td.sceau .badge', 'échec');
        $this->assertSelectorTextContains('#releve-2 td.sceau', 'broken modifié depuis le scellé');
        // Checked, even if it failed: the weekly tick checks it again, or a new seal does.
        $this->assertCount(0, $client->getCrawler()->filter('form[action="/admin/releves/verifier/2"]'));
    }

    public function testCheckingAllLeavesTheSealsAlreadyChecked(): void
    {
        $client = $this->loggedIn();
        $client->request('GET', '/admin/releves');
        $this->assertSelectorTextContains('form[action="/admin/releves/verifier"] button', 'Tout vérifier maintenant (1)');
        $token = $client->getCrawler()->filter('form[action="/admin/releves/verifier"] input[name="_token"]')->attr('value');
        $client->submit($client->getCrawler()->filter('form[action="/admin/releves/verifier"]')->form());
        $client->followRedirect();

        $this->assertSelectorTextContains('.flash', 'conforme');
        $this->assertCount(0, $client->getCrawler()->filter('form[action="/admin/releves/verifier"]'));
        $this->assertSelectorTextContains('main', 'Tous les sceaux ont été vérifiés.');

        // Asked anyway, with nothing left to check: Sablier is not run again.
        $client->request('POST', '/admin/releves/verifier', ['_token' => $token]);
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Aucun sceau en attente de vérification');
    }

    public function testCheckingRequiresTheFormsToken(): void
    {
        $client = $this->loggedIn();
        $client->request('POST', '/admin/releves/verifier/2', ['_token' => 'forged']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAnAnonymousVisitorCannotAskForACheck(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/releves/verifier/2');

        $this->assertResponseRedirects('/admin/connexion');
    }

    private function loggedIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->logInAdmin($client);

        return $client;
    }
}
