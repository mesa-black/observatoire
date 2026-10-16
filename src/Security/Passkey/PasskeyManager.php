<?php

namespace App\Security\Passkey;

use App\Entity\Passkey;
use App\Repository\PasskeyRepository;
use App\Security\AdminUser;
use Cose\Algorithms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The two WebAuthn ceremonies for the one admin, adapted from Show me the REX:
 * registration (logged in: options with the challenge kept in the session,
 * then the attestation is verified and stored), and login (options with no
 * credential list, so the device offers the passkey it holds for this site,
 * then the assertion is checked against the stored record).
 * User verification is required both ways; attestation is not requested.
 */
final class PasskeyManager
{
    private const string RP_NAME = 'Observatoire post-quantique';
    private const string SESSION_CREATION = '_passkey_creation';
    private const string SESSION_REQUEST = '_passkey_request';
    private const int TIMEOUT_MS = 60000;

    private readonly SerializerInterface $serializer;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasskeyRepository $passkeys,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->serializer = (new WebauthnSerializerFactory(AttestationStatementSupportManager::create([new NoneAttestationStatementSupport()])))->create();
    }

    /** @return array<string, mixed> for navigator.credentials.create() */
    public function creationOptions(Request $request): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(self::RP_NAME, $request->getHost()),
            PublicKeyCredentialUserEntity::create(AdminUser::USERNAME, AdminUser::USERNAME, 'Administration de l\'observatoire'),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256), PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256)],
            AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(fn (Passkey $p) => $this->record($p)->getPublicKeyCredentialDescriptor(), $this->passkeys->all()),
            self::TIMEOUT_MS,
        );
        $json = $this->serializer->serialize($options, 'json');
        $request->getSession()->set(self::SESSION_CREATION, $json);

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function register(string $responseJson, string $name, Request $request): Passkey
    {
        $pending = $request->getSession()->remove(self::SESSION_CREATION);
        if (!\is_string($pending)) {
            throw new PasskeyException('Aucun enregistrement en cours.');
        }
        try {
            $options = $this->serializer->deserialize($pending, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;
            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyException('Réponse inattendue.');
            }
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($request)->creationCeremony())
                ->check($response, $options, $request->getHost());
        } catch (PasskeyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Passkey registration refused', ['exception' => $e]);
            throw new PasskeyException('La clé d\'accès n\'a pas pu être vérifiée.', 0, $e);
        }
        $passkey = new Passkey(self::base64url($record->publicKeyCredentialId), mb_substr('' !== trim($name) ? trim($name) : 'Clé d\'accès', 0, 64), $this->toArray($record), $this->clock->now());
        $this->em->persist($passkey);
        $this->em->flush();

        return $passkey;
    }

    /** @return array<string, mixed> for navigator.credentials.get() */
    public function requestOptions(Request $request): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $request->getHost(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        );
        $json = $this->serializer->serialize($options, 'json');
        $request->getSession()->set(self::SESSION_REQUEST, $json);

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function authenticate(string $responseJson, Request $request): void
    {
        $pending = $request->getSession()->remove(self::SESSION_REQUEST);
        if (!\is_string($pending)) {
            throw new PasskeyException('Aucune connexion en cours.');
        }
        try {
            $options = $this->serializer->deserialize($pending, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;
            if (!$response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyException('Réponse inattendue.');
            }
            $passkey = $this->passkeys->findOneByWebauthnId(self::base64url($credential->rawId)) ?? throw new PasskeyException('Clé inconnue.');
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies($request)->requestCeremony())
                ->check($this->record($passkey), $response, $options, $request->getHost(), $response->userHandle);
        } catch (PasskeyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Passkey login refused', ['exception' => $e]);
            throw new PasskeyException('La clé d\'accès n\'a pas pu être vérifiée.', 0, $e);
        }
        $passkey->used($this->toArray($record), $this->clock->now());
        $this->em->flush();
    }

    private function ceremonies(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$request->getSchemeAndHttpHost()]);

        return $factory;
    }

    private function record(Passkey $passkey): CredentialRecord
    {
        return $this->serializer->deserialize(json_encode($passkey->getRecord(), \JSON_THROW_ON_ERROR), CredentialRecord::class, 'json');
    }

    /** @return array<string, mixed> */
    private function toArray(CredentialRecord $record): array
    {
        $array = json_decode($this->serializer->serialize($record, 'json'), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($array));

        return $array;
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
