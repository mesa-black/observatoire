<?php

namespace App\Tests\Controller;

use App\Releve\PreviewLinks;
use App\Releve\ReleveRepository;
use App\Releve\SealVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The seal on the relevé's own page: said to the public only once Sablier
 * found it sound here. Relevé 2 of the fixtures is sealed and not out yet, so
 * it is read through its preview link.
 */
final class PublicSealTest extends WebTestCase
{
    protected function setUp(): void
    {
        putenv('FAKE_SABLIER_BROKEN');
        foreach (glob(\dirname(__DIR__, 2).'/var/test/seals/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    protected function tearDown(): void
    {
        putenv('FAKE_SABLIER_BROKEN');
        parent::tearDown();
    }

    public function testNothingIsSaidOfASealNotCheckedYet(): void
    {
        $this->page();

        $this->assertSelectorNotExists('.sceau-tete');
        $this->assertSelectorNotExists('#sceau');
    }

    public function testASoundSealIsShownWithHowToCheckIt(): void
    {
        $client = static::createClient();
        $this->check();
        $this->page($client);

        $this->assertSelectorTextContains('.sceau-tete', 'Données scellées le 15/10/2026');
        $this->assertSelectorExists('.sceau-tete a[href="#sceau"]');
        $this->assertSelectorTextContains('#sceau', 'Les 7 fichiers de ce relevé');
        $this->assertSelectorTextContains('#sceau', 'le 15/10/2026 à 10:00 (heure de Paris)');
        $this->assertSelectorTextContains('#sceau', 'Ed25519 et en ML-DSA-65');
        $this->assertSelectorTextContains('#sceau', 'datée par DigiCert');
        $this->assertSelectorTextContains('#sceau', 'au bloc 976246');
        $this->assertSelectorTextContains('#sceau pre', 'git clone --branch v0.14.0 https://github.com/mesa-black/sablier');
        $this->assertSelectorTextContains('#sceau pre', 'verify releves/2/seal.sig --declare=sablier.json');
        $this->assertSelectorTextContains('#sceau', 'avec PHP 8.4 ou plus récent, ou à défaut Docker');
    }

    public function testAFailedSealIsNotShown(): void
    {
        $client = static::createClient();
        putenv('FAKE_SABLIER_BROKEN=1');
        $this->check();
        $this->page($client);

        $this->assertSelectorNotExists('.sceau-tete');
        $this->assertSelectorNotExists('#sceau');
    }

    private function check(): void
    {
        $container = static::getContainer();
        $releve = $container->get(ReleveRepository::class)->find(2) ?? throw new \LogicException('fixture relevé 2');
        $container->get(SealVerifier::class)->verify($releve);
    }

    private function page(?KernelBrowser $client = null): KernelBrowser
    {
        $client ??= static::createClient();
        /** @var PreviewLinks $links */
        $links = static::getContainer()->get(PreviewLinks::class);
        $client->request('GET', '/apercu/2/'.$links->token(2));
        $this->assertResponseIsSuccessful();

        return $client;
    }
}
