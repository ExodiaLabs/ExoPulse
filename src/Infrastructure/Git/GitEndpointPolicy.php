<?php

declare(strict_types=1);

namespace App\Infrastructure\Git;

use App\Domain\Git\ProviderException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GitEndpointPolicy
{
    public function __construct(#[Autowire('%env(EXOPULSE_GIT_HOSTS)%')] private string $hosts, #[Autowire('%env(EXOPULSE_INTERNAL_GIT_HOSTS)%')] private string $internalHosts)
    {
    }

    public function validate(string $url, bool $base = false): string
    {
        $parts = parse_url($url);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? '') || !isset($parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment']) || ($base && isset($parts['query'])) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new ProviderException('URL Git non autorisée.');
        }
        $host = strtolower($parts['host']);
        $allowed = array_map('trim', explode(',', strtolower($this->hosts.','.$this->internalHosts)));
        if (!in_array($host, $allowed, true)) {
            throw new ProviderException('Hôte Git absent de la liste autorisée.');
        }

        return $host;
    }

    public function internal(string $host): bool
    {
        return in_array($host, array_map('trim', explode(',', strtolower($this->internalHosts))), true);
    }
}
