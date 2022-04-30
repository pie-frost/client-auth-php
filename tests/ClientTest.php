<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth\Tests;

use DateInterval;
use DateTime;
use ParagonIE\ConstantTime\Base32Hex;
use ParagonIE\Paserk\Operations\Key\SealingPublicKey;
use ParagonIE\Paserk\Operations\Key\SealingSecretKey;
use ParagonIE\Paserk\Types\Seal;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;
use PHPUnit\Framework\TestCase;
use PIEFrost\ClientAuth\AuthServer;
use PIEFrost\ClientAuth\Client;
use PIEFrost\ClientAuth\Config;

class ClientTest extends TestCase
{
    private ?Config $config = null;
    private ?AuthServer $authServer = null;
    private ?SealingSecretKey $serverSK = null;
    private ?SealingSecretKey $clientSK = null;

    /**
     * @before
     */
    public function before()
    {
        $this->clientSK = SealingSecretKey::generate(new Version4());
        $this->serverSK = SealingSecretKey::generate(new Version4());
        $this->authServer = new AuthServer(
            $this->serverSK->getPublicKey(),
            'http://auth.localhost',
            'auth.localhost'
        );
        $this->config = (new Config())
            ->withAuthServer($this->authServer)
            ->withCertaintyPath(__DIR__ . '/certs')
            ->withDomain('phpunit.localhost')
            ->withSecretKey($this->clientSK);
    }

    public function testCreateAuthRequestToken()
    {
        $challenge = Base32Hex::encodeUnpadded(random_bytes(20));
        $client = Client::fromConfig($this->config);
        $requestToken = $client->createAuthRequestToken(
            $challenge,
            'http://phpunit.localhost/callback'
        );
        $this->assertStringStartsWith('v4.public.', $requestToken);
    }

    public function testDecrypt()
    {
        $challenge = Base32Hex::encodeUnpadded(random_bytes(20));
        $userID = Base32Hex::encodeUnpadded(random_bytes(20));

        $client = Client::fromConfig($this->config);
        $user = $client->processAuthResponse(
            $this->dummyResponse(
                $challenge,
                [
                    'username' => 'john.doe',
                    'domain' => 'phpunit.localhost',
                    'userid' => $userID
                ]
            )
        );

        $this->assertSame('phpunit.localhost', $user->getDomain());
        $this->assertSame('john.doe', $user->getUsername());
        $this->assertSame($userID, $user->getUserId());
    }

    protected function dummyResponse(
        string $challenge,
        array $userData
    ): string {
        $v4 = new Version4();
        $now = new DateTime('NOW');
        /** @var SealingPublicKey $pk */
        $pk = $this->clientSK->getPublicKey();

        $oneTimeKey = SymmetricKey::generate($v4);
        $secret = (new Builder())
            ->setVersion($v4)
            ->setPurpose(Purpose::local())
            ->setKey($oneTimeKey)
            ->set('challenge', $challenge)
            ->set('username', $userData['username'])
            ->set('org', $userData['domain'])
            ->set('userid', $userData['userid'])
            ->setNotBefore($now)
            ->setIssuedAt($now)
            ->setAudience($this->config->getDomain())
            ->setExpiration(
                (clone $now)->add(new DateInterval('PT20M'))
            )
        ->toString();
        $sealed = (new Seal($pk))->encode($oneTimeKey);

        return (new Builder())
            ->setVersion($v4)
            ->setPurpose(Purpose::public())
            ->setKey($this->serverSK->toPasetoKey())
            ->setAudience($userData['domain'])
            ->set('secret', $secret)
            ->set('sealed', $sealed)
            ->setExpiration(
                (clone $now)->add(new DateInterval('PT15M'))
            )
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setSubject('auth.localhost')
        ->toString();
    }
}
