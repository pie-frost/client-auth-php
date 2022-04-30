<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth;

use DateInterval;
use DateTime;
use GuzzleHttp\Client as HttpClient;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\Paserk\{
    Operations\Key\SealingSecretKey,
    PaserkException,
    Types\Seal
};
use ParagonIE\Paseto\{
    Builder,
    Exception\InvalidVersionException,
    Exception\PasetoException,
    JsonToken,
    Keys\SymmetricKey,
    Parser,
    Protocol\Version4,
    ProtocolCollection,
    Purpose,
    Rules\ForAudience,
    Rules\NotExpired
};
use SodiumException;
use TypeError;

class Client
{
    public function __construct(
        protected HttpClient $http,
        protected AuthServer $server,
        protected SealingSecretKey $clientSecretKey,
        protected string $clientDomain
    ) {}

    /**
     * @throws CertaintyException
     * @throws SodiumException
     */
    final public static function fromConfig(Config $config): static
    {
        return new static(
            $config->getHttpClient(),
            $config->getAuthServer(),
            $config->getSecretKey(),
            $config->getDomain()
        );
    }

    /**
     * @throws InvalidVersionException
     * @throws PasetoException
     */
    final public function createAuthRequestToken(
        string $challenge,
        string $redirectUrl
    ): string {
        $now = new DateTime('NOW');
        $exp = (clone $now)->add(new DateInterval('PT5M'));
        return (new Builder())
            ->setVersion(new Version4())
            ->setPurpose(Purpose::public())
            ->setKey($this->clientSecretKey->toPasetoKey())
            ->setExpiration($exp)
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setAudience($this->server->getDomain())
            ->set('challenge', $challenge)
            ->set('url', $redirectUrl)
        ->toString();
    }

    /**
     * @throws InvalidVersionException
     * @throws PaserkException
     * @throws PasetoException
     */
    final public function processAuthResponse(string $paseto): User
    {
        /* Validate the server response against the public key */
        $outerToken = $this->server->parseToken($paseto);

        /* Decrypt the inner token ("secret") using the wrapped
           symmetric key ("sealed") */
        $innerToken = $this->decryptSecretPayload(
            $outerToken->get('secret'),
            $outerToken->get('sealed')
        );

        /* Return a user object */
        return User::fromToken($innerToken);
    }

    /**
     * YOU CAN OVERRIDE THIS METHOD!
     *
     * Just call parent::getSecretPayloadParser($key) and add any validation
     * rules you feel necessary.
     *
     * @param SymmetricKey $key
     * @return Parser
     *
     * @throws InvalidVersionException
     * @throws PasetoException
     */
    public function getSecretPayloadParser(SymmetricKey $key): Parser
    {
        return (new Parser())
            ->setAllowedVersions(ProtocolCollection::v4())
            ->setPurpose(Purpose::local())
            ->setKey($key)
            ->addRule(new NotExpired())
            ->addRule(new ForAudience($this->clientDomain));
    }

    /**
     * Decrypt the "secret" component of an AuthServer response
     * (using the "sealed" key).
     *
     * @throws PasetoException
     * @throws PaserkException
     */
    private function decryptSecretPayload(
        string $secret,
        string $sealed
    ): JsonToken {
        if ($secret[0] === 'k' && $sealed[0] === 'v') {
            /* You got the order wrong, but that's ok. */
            return $this->decryptSecretPayload($sealed, $secret);
        }

        $oneTimeKey = Seal::fromSecretKey($this->clientSecretKey)
            ->decode($sealed);
        if (!($oneTimeKey instanceof SymmetricKey)) {
            throw new TypeError("One-time key MUST be a symmetric key");
        }

        return $this->getSecretPayloadParser($oneTimeKey)
            ->parse($secret);
    }
}
