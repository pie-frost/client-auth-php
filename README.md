# Client Authentication (PHP)

[![Build Status](https://github.com/pie-frost/common/actions/workflows/ci.yml/badge.svg)](https://github.com/pie-frost/client-auth-php/actions)
[![Psalm Status](https://github.com/pie-frost/common/actions/workflows/psalm.yml/badge.svg)](https://github.com/pie-frost/client-auth-php/actions)
[![Latest Stable Version](https://poser.pugx.org/pie-frost/client-auth/v/stable)](https://packagist.org/packages/pie-frost/client-auth)
[![Latest Unstable Version](https://poser.pugx.org/pie-frost/client-auth/v/unstable)](https://packagist.org/packages/pie-frost/client-auth)
[![License](https://poser.pugx.org/pie-frost/client-auth/license)](https://packagist.org/packages/pie-frost/client-auth)
[![Downloads](https://img.shields.io/packagist/dt/pie-frost/client-auth.svg)](https://packagist.org/packages/pie-frost/client-auth)

Client-side library for authenticating with the Bifrost Authentication Server.

## Installation

Use [Composer](https://getcomposer.org/download).

```terminal
composer require pie-frost/client-auth
```

## Configuration

The minimum configuration for the Authentication Client is as follows:

1. The authentication server's public key
    * Also, URL, but the hard-coded default is correct for our instance
2. Your server's signing keypair (should be loaded as a PASERK sealing key)
3. Your server's domain name

```php
<?php
declare(strict_types=1); // Recommended!

/* Namespace imports */
use PIEFrost\ClientAuth\AuthServer;
use PIEFrost\ClientAuth\Config;
use ParagonIE\Paserk\Operations\Key\SealingSecretKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Protocol\Version4;

/* Get from Auth server; auto-discovery coming soon */
$serverPublicKey = new AsymmetricPublicKey(
    '',
    new Version4()
);

/* The defaults are fine. */
$authServer = new AuthServer($serverPublicKey);

/* Generate this once, then persist it.
 *
 * The corresponding Public Key needs to be added to the
 * authentication server.
 */
$mySecretKey = SealingSecretKey::generate(new Version4());
$myPublicKey = $mySecretKey->getPublicKey();
// To get a copy of this key in the format expected by the Auth Server:
// echo $myPublicKey->encode();

$config = (new Config())
    ->withAuthServer($authServer)
    ->withDomain('my-custom-service.foo.bar')
    ->withSecretKey($mySecretKey);
```

There are additional configuration options available, of course.

## Usage

Once you have a configuration object loaded, the client can be loaded and used.

```php
<?php
declare(strict_types=1); // Recommended!

/* Namespace imports */
use PIEFrost\ClientAuth\AuthServer;
use PIEFrost\ClientAuth\Client;
use PIEFrost\ClientAuth\Config;
use ParagonIE\Paserk\Operations\Key\SealingSecretKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Protocol\Version4;

/** @var Config $config */
$client = Client::fromConfig($config);
```

#### Create Auth Request Token

To begin the authentication request workflow, you must have registered at least one
Redirect URL with the authentication server.

The steps you will be performing are as follows:

1. Generate (and persist, preferably in a PHP session) a 256-bit secret "challenge".
   The primary purpose of the challenge is to prevent replay and confused deputy
   attacks.
2. Create a request token.
3. Redirect the user to the auth server, making sure to specify the return URL and
   token (step 2).

```php
<?php
declare(strict_types=1);

use PIEFrost\ClientAuth\Client;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * @var Client $client 
 */
 
$redirectURL = 'https://example.com/bifrost-callback';

// Step 1.
$_SESSION['challenges']['bifrost-auth'] = Base64UrlSafe::encodeUnpadded(random_bytes(32));

// Step 2.
$token = $client->createAuthRequestToken(
    $_SESSION['challenges']['bifrost-auth'],
    $redirectURL
);

// Step 3:
header('Location: ' . $client->getAuthServer()->getAuthUrl([
    'challenge' => 
        $_SESSION['challenges']['bifrost-auth'],
    'url' =>
        $redirectURL,
    'paseto' =>
        $token
]));
exit;
```

Once your user is at the Authentication Server, they'll do the necessary steps to authenticate
and then return to the callback URL.

#### Processing the Auth Server Response

```php
<?php
declare(strict_types=1); // Recommended!

use PIEFrost\ClientAuth\Client;

/**
 * @var Client $client
 */
if (!isset($_GET['paseto']) || !isset($_SESSION['challenges']['bifrost-auth'])) {
    // Invalid state, redirect user and terminate execution
    header('Location: /');
    exit;
}

$userInfo = $client->processAuthResponse(
    $_GET['paseto'], 
    $_SESSION['challenges']['bifrost-auth']
);
var_dump($userInfo);
```

Upon success, the `var_dump()` will return the following information:

1. Username for the authenticated user.
2. Unique ID for the authenticated user.
3. The domain name for the given user.

What you *actually do* with this information is up to you.

## What Is Actually Happening?

The PIE-Frost project has an Authentication Server that implements an opinionated
single sign-on protocol.

The workflow looks like this:

1. Client generates a random challenge, then [signs a `v4.public` PASETO](https://github.com/paseto-standard/paseto-spec/blob/master/docs/01-Protocol-Versions/Version4.md#sign)
   that covers the challenge and the callback URL.
2. The user is redirected to the authentication server, with the PASETO from step 1.  
   (You can think of this initial token as a hall pass from your application.)
3. The authentication server validates the PASETO.
   * If the user is already logged into the server, they move onto step 4.
   * Otherwise, they're expected to sign in to the authentication server, which only
     permits hardware keys ([WebAuthn](https://webauthn.guide)).
   * (There are server-side validation steps involved, but those aren't super important for clients to understand.)
4. The server generates its response token. 
   1. The server [encrypts the user's information and challenge into a `v4.local` PASETO](https://github.com/paseto-standard/paseto-spec/blob/master/docs/01-Protocol-Versions/Version4.md#encrypt),
      using a random one-time key.  
   2. This random one-time key is then [encrypted with your application's public key, using PASERK `k4.seal`](https://github.com/paseto-standard/paserk/blob/master/types/seal.md).
   3. Both of the above elements are bundled together and [signed by the server into a `v4.public` PASETO](https://github.com/paseto-standard/paseto-spec/blob/master/docs/01-Protocol-Versions/Version4.md#sign).
      This gets provided to the suer.
5. The user is redirected to your callback URL, with the token from step 4.
6. The server response is verified and deserialized.
   1. The outer `v4.public` PASETO's signature is verified.
   2. The one-time key is decrypted using your application's secret key.
   3. The inner `v4.local` PASETO is decrypted and verified.
      1. The `challenge` claim is compared with the one generated in step 1.
      2. The `org` claim is compared to [the domain configured](#configuration). 

After step 6, you have a cryptographically authenticated data structure containing the user information
provided by the Authentication Server.

## Questions and Answers

### Why Not Just Use SAML, OAuth, or OpenID Connect?

We wanted to completely avoid the complexity of XML, X.509, ASN.1, and DER/BER encoding. Additionally,
we wanted to [avoid using JWT](https://paragonie.com/blog/2017/03/jwt-json-web-tokens-is-bad-standard-that-everyone-should-avoid).

This left us without any options, so we decided to build a minimalistic, opinionated authentication flow.

Design decisions made:

1. The only digital signature algorithm supported in this workflow is **Ed25519** (including WebAuthn).
2. We constrained the token formats to `v4.public` (Ed25519) and `v4.local` (XChaCha20 + BLAKE2b-MAC) PASETO. 
3. For the asymmetric encryption (for sending user information from the authentication server to the application
   server), we also permit one `k4.seal` PASERK (ephemeral-static X25519 + XChaCha20 + BLAKE2b-MAC).
   This prevents a malicious user from learning any useful information about their user account (i.e. unique ID).
4. We placed the challenge inside the `k4.seal`-encrypted PASERK to ensure the encryption was being respected in
   order for the challenge to be verified client-side.
5. All algorithm implementations are provided by [libsodium](https://doc.libsodium.org).
6. There is no runtime negotiation of any algorithm choices.
