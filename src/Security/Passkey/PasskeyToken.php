<?php

namespace App\Security\Passkey;

use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * A session opened with a passkey. A class of its own on purpose: scheb/2fa
 * asks for the code after the token classes listed in scheb_2fa.yaml, and a
 * passkey with user verification (Touch ID, Face ID, PIN) is already two
 * factors, phishing-resistant: the code is not asked again.
 */
final class PasskeyToken extends PostAuthenticationToken
{
}
