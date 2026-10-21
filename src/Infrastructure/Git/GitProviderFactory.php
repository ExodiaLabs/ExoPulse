<?php

declare(strict_types=1);

namespace App\Infrastructure\Git;

use App\Domain\Git\GitProvider;
use App\Domain\Git\ProviderException;
use App\Domain\Model\ProviderConnection;
use App\Infrastructure\Security\SecretCipher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GitProviderFactory
{
    public function __construct(#[Autowire(service: 'exopulse.git_http_client')] private HttpClientInterface $client, private SecretCipher $cipher, private GitEndpointPolicy $policy)
    {
    }

    public function forConnection(ProviderConnection $connection): GitProvider
    {
        $host = $this->policy->validate($connection->baseUrl, true);
        $base = rtrim($connection->baseUrl, '/');
        $token = $this->cipher->decrypt($connection->encryptedToken);
        if ('' === $token || preg_match('/[\r\n]/', $token)) {
            throw new ProviderException('Identifiant Git invalide.');
        }
        if ('github' === $connection->provider) {
            $base = in_array($host, ['github.com', 'api.github.com'], true) ? 'https://api.github.com' : $base.'/api/v3';
            $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/vnd.github+json'];
        } elseif ('gitlab' === $connection->provider) {
            $base .= '/api/v4';
            $headers = ['PRIVATE-TOKEN' => $token, 'Accept' => 'application/json'];
        } else {
            throw new ProviderException('Fournisseur Git non pris en charge.');
        }
        $apiHost = $this->policy->validate($base, true);
        // Symfony pins resolved public addresses and checks the connected IP.
        // Internal networks require an explicit administrator-controlled hostname.
        $client = new NoPrivateNetworkHttpClient($this->client, allowList: $this->policy->internal($apiHost) ? ['0.0.0.0/0', '::/0'] : []);
        $api = new GitApi($client, $this->policy, $base, $headers);

        return 'github' === $connection->provider ? new GithubProvider($api) : new GitlabProvider($api);
    }
}
