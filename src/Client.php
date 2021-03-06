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
use ParagonIE\ConstantTime\Base64UrlSafe;
use PIEFrost\ClientAuth\Exception\AuthFailedException;
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
     * @throws AuthFailedException
     * @throws InvalidVersionException
     * @throws PaserkException
     * @throws PasetoException
     */
    final public function processAuthResponse(
        string $paseto,
        string $challenge
    ): User {
        /* Validate the server response against the public key */
        $outerToken = $this->server->parseToken($paseto);

        /* Decrypt the inner token ("secret") using the wrapped
           symmetric key ("sealed") */
        $innerToken = $this->decryptSecretPayload(
            $outerToken->get('secret'),
            $outerToken->get('sealed')
        );

        /* Validate the token was minted for us: */
        $this->verifyChallenge($challenge, $innerToken);
        $this->verifyOrgDomain($innerToken);

        /* Return a user object */
        return User::fromToken($innerToken);
    }

    /**
     * You can override this in a child class to disable organization verification
     * or to add multiple valid domain names (e.g. different TLDs).
     *
     * By default, we only allow the one.
     *
     * @throws AuthFailedException
     * @throws PasetoException
     */
    protected function verifyOrgDomain(JsonToken $innerToken): void
    {
        $org = $innerToken->get('org');
        if (!hash_equals($this->clientDomain, $org)) {
            throw new AuthFailedException(
                "Domain mismatch. Expected: {$this->clientDomain}; Actual: {$org}");
        }
    }

    /**
     * You can (but SHOULDN'T) override this in a child class to disable challenge/response
     * authentication.
     *
     * There's almost no real-world benefit to doing this.
     *
     * @throws AuthFailedException
     * @throws PasetoException
     */
    protected function verifyChallenge(string $challenge, JsonToken $innerToken): void
    {
        if (!hash_equals($challenge, $innerToken->get('challenge'))) {
            throw new AuthFailedException("Challenge/response authentication failed");
        }
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

    final public function getAuthServer(): AuthServer
    {
        return $this->server;
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
