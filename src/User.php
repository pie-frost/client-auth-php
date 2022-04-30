<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth;

use ParagonIE\Paseto\JsonToken;

class User
{
    public function __construct(
        private string $username,
        private string $userId,
        private string $domain
    ) {}

    public static function fromToken(JsonToken $token): static
    {
        return new static(
            $token->get('username'),
            $token->get('userid'),
            $token->get('org')
        );
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
