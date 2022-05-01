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

### Create Auth Request Token

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

## Processing the Auth Server Response

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
