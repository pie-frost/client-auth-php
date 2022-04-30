<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth;

use ParagonIE\Paseto\{
    Exception\InvalidVersionException,
    Exception\PasetoException,
    JsonToken,
    Keys\AsymmetricPublicKey,
    Parser,
    ProtocolCollection,
    Purpose,
    Rules\NotExpired,
    Rules\Subject
};
use function http_build_query;

final class AuthServer
{
    public function __construct(
        protected AsymmetricPublicKey $serverPublicKey,
        protected string $url = 'https://auth.piefrost.com',
        protected string $domain = 'auth.piefrost.com'
    ) {}

    public function getAuthUrl(array $params = []): string
    {
        if (!empty($params)) {
            return $this->url . '/auth?' . http_build_query($params);
        }
        return $this->url . '/auth';
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @throws InvalidVersionException
     * @throws PasetoException
     */
    public function parseToken(string $token): JsonToken
    {
        return (new Parser())
            ->setAllowedVersions(ProtocolCollection::v4())
            ->setPurpose(Purpose::public())
            ->setKey($this->serverPublicKey)
            ->addRule(new NotExpired())
            ->addRule(new Subject($this->domain))
        ->parse($token);
    }
}
