<?php

declare(strict_types=1);

namespace App\Infrastructure\Registry;

use App\Domain\Analysis\ManifestData;
use App\Domain\Analysis\OfflineSupportCatalog;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class LifecycleCatalog implements OfflineSupportCatalog
{
    public function __construct(private readonly CatalogHttpClient $httpClient, private readonly CacheInterface $cache, private readonly BundledLifecycle $bundled = new BundledLifecycle())
    {
    }

    public function lookupOffline(string $technology, string $version): ?array
    {
        return $this->bundled->lookup($technology, $version);
    }

    public function lookup(string $technology, string $version): ?array
    {
        $primary = $this->lookupOffline($technology, $version);
        if (null !== $primary) {
            return $primary;
        }
        if ('Symfony (composants)' === $technology) {
            $technology = 'Symfony';
        }
        $product = match ($technology) {
            'PHP' => 'php',
            'Node.js' => 'nodejs',
            'Symfony' => 'symfony',
            'Vue' => 'vue',
            default => null,
        };
        if (null === $product || !preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.\d+)?$/', $version, $parts)) {
            return null;
        }
        $cycle = 'nodejs' === $product || 'vue' === $product ? $parts[1] : (isset($parts[2]) ? $parts[1].'.'.$parts[2] : null);
        if (null === $cycle) {
            return null;
        }
        $url = 'https://endoflife.date/api/v1/products/'.$product;
        /** @var array{releases: list<array<array-key, mixed>>, verifiedAt: string} $catalog */
        $catalog = $this->cache->get('exopulse_lifecycle_'.$product, function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(86400);
            $data = $this->httpClient->get($url);
            $result = ManifestData::map($data['result'] ?? null);
            $releases = array_values(array_filter(ManifestData::map($result['releases'] ?? null), is_array(...)));
            if ([] === $releases) {
                throw new RegistryUnavailable('Calendrier de support indisponible.');
            }

            return ['releases' => $releases, 'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        });
        $target = null;
        foreach ($catalog['releases'] as $release) {
            if (true === ($release['isMaintained'] ?? false)) {
                $latest = ManifestData::map($release['latest'] ?? null);
                $candidate = ManifestData::text($latest['name'] ?? null);
                if (null !== $candidate && (null === $target || version_compare($candidate, $target, '>'))) {
                    $target = $candidate;
                }
            }
        }
        foreach ($catalog['releases'] as $release) {
            if (($release['name'] ?? null) === $cycle) {
                return ['eol' => $this->date($release['eolFrom'] ?? null), 'endOfMaintenance' => $this->date($release['eoasFrom'] ?? null), 'source' => $url, 'verifiedAt' => $catalog['verifiedAt'], 'target' => $target];
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
