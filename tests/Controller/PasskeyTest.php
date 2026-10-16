<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasskeyTest extends WebTestCase
{
    use LogsInAdmin;

    public function testLoginOptionsArePublicAndAskForUserVerification(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/cle/connexion/options', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_HOST' => 'observatoire.test']);
        $this->assertResponseIsSuccessful();
        $options = json_decode((string) $client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        $this->assertSame('observatoire.test', $options['rpId']);
        $this->assertSame('required', $options['userVerification']);
        $this->assertNotEmpty($options['challenge']);
    }

    public function testAForgedAssertionDoesNotLogIn(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/cle/connexion/options', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $client->request('POST', '/admin/cle/connexion', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'CONTENT_TYPE' => 'application/json'], content: '{"id":"x","rawId":"eA","type":"public-key","response":{"clientDataJSON":"e30","authenticatorData":"AA","signature":"AA"}}');
        $this->assertResponseStatusCodeSame(401);
        $client->request('GET', '/admin');
        $this->assertResponseRedirects('/admin/connexion');
    }

    public function testAddingAKeyNeedsTheAdmin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/cle/options', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertResponseRedirects('/admin/connexion');

        $this->logInAdmin($client);
        $client->request('POST', '/admin/cle/options', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertResponseIsSuccessful();
        $options = json_decode((string) $client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
    }
}
