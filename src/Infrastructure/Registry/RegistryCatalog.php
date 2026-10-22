<?php

declare(strict_types=1);

namespace App\Infrastructure\Registry;

use App\Domain\Analysis\ManifestData;
use App\Domain\Analysis\PackageCatalog;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class RegistryCatalog implements PackageCatalog
{
    public function __construct(private readonly CatalogHttpClient $httpClient, private readonly CacheInterface $cache)
    {
    }

    /** @return array{versions: list<string>, source: string, verifiedAt: string} */
    public function versions(string $ecosystem, string $name): array
    {
        if (!preg_match('~^(?:@[a-z0-9_.-]+/)?[a-z0-9_.-]+(?:/[a-z0-9_.-]+)?$~i', $name) || str_contains($name, '..')) {
            throw new RegistryUnavailable('Coordonnées de package non prises en charge.');
        }
        $url = 'composer' === $ecosystem ? 'https://repo.packagist.org/p2/'.$name.'.json' : 'https://registry.npmjs.org/'.rawurlencode($name);

        return $this->cache->get('exopulse_registry_'.hash('sha256', $url), function (ItemInterface $item) use ($url, $ecosystem, $name): array {
            $item->expiresAfter(21600);
            $data = $this->httpClient->get($url);
            $versions = [];
            if ('composer' === $ecosystem) {
                $packages = ManifestData::map($data['packages'] ?? null);
                foreach (ManifestData::map($packages[$name] ?? null) as $entry) {
                    $package = ManifestData::map($entry);
                    $version = ManifestData::text($package['version'] ?? null);
                    if (null !== $version) {
                        $versions[] = $version;
                    }
                }
            } else {
                foreach (array_keys(ManifestData::map($data['versions'] ?? null)) as $version) {
                    $versions[] = (string) $version;
                }
            }
            if ([] === $versions) {
                throw new RegistryUnavailable('Versions publiées indisponibles.');
            }

            return ['versions' => $versions, 'source' => $url, 'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        });
    }
}
