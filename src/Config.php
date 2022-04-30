<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth;

use GuzzleHttp\Client as HttpClient;
use ParagonIE\Certainty\{
    Exception\CertaintyException,
    Fetch,
    RemoteFetch
};
use ParagonIE\Paserk\Operations\Key\SealingSecretKey;
use Psalm\Exception\ConfigException;
use SodiumException;

class Config
{
    private ?AuthServer $server = null;
    private ?HttpClient $http = null;
    private ?SealingSecretKey $secretKey = null;
    private ?string $certaintyPath = null;
    private ?string $domain = null;

    /**
     * @throws ConfigException
     */
    final public function getAuthServer(): AuthServer
    {
        if (is_null($this->server)) {
            throw new ConfigException("Server not configured");
        }
        return $this->server;
    }

    /**
     * @throws ConfigException
     */
    final public function getDomain(): string
    {
        if (is_null($this->domain)) {
            throw new ConfigException("Client domain not configured");
        }
        return $this->domain;
    }

    /**
     * You can override this!
     *
     * @return RemoteFetch
     * @throws CertaintyException
     * @throws SodiumException
     */
    public function getLatestCertFetcher(): Fetch
    {
        /* Default: RemoteFetch */
        return new RemoteFetch(
            $this->certaintyPath ?? dirname(__DIR__) . '/data'
        );
    }

    /**
     * @throws CertaintyException
     * @throws SodiumException
     */
    final public function getHttpClient(): HttpClient
    {
        if (is_null($this->http)) {
            $this->http = new HttpClient([
                'verify' => $this->getLatestCertFetcher()
            ]);
        }
        return $this->http;
    }

    /**
     * @throws ConfigException
     */
    final public function getSecretKey(): SealingSecretKey
    {
        if (is_null($this->secretKey)) {
            throw new ConfigException("Client secret key not configured");
        }
        return $this->secretKey;
    }

    final public function withAuthServer(AuthServer $server): self
    {
        $self = clone $this;
        $self->server = $server;
        return $self;
    }

    final public function withCertaintyPath(string $path): self
    {
        $self = clone $this;
        $self->certaintyPath = $path;
        return $self;
    }

    final public function withDomain(string $domain): self
    {
        $self = clone $this;
        $self->domain = $domain;
        return $self;
    }

    final public function withHttpClient(HttpClient $client): self
    {
        $self = clone $this;
        $self->http = $client;
        return $self;
    }

    final public function withSecretKey(SealingSecretKey $secret): self
    {
        $self = clone $this;
        $self->secretKey = $secret;
        return $self;
    }
}
