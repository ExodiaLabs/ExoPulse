<?php

declare(strict_types=1);

namespace App\Infrastructure\Registry;

use App\Domain\Analysis\ManifestData;

/** Bounded historical reference; an absent series is never supported by default. */
final class BundledLifecycle
{
    /** @var array<array-key, mixed>|null */
    private ?array $reference = null;

    /** @return array{eol: ?string, endOfMaintenance: ?string, source: string, verifiedAt: string, target: ?string, precision: string}|null */
    public function lookup(string $technology, string $version): ?array
    {
        if ('Symfony (composants)' === $technology) {
            $technology = 'Symfony';
        }
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.\d+)?$/', $version, $parts)) {
            return null;
        }
        $cycle = in_array($technology, ['Node.js', 'Vue'], true) ? $parts[1] : (isset($parts[2]) ? $parts[1].'.'.$parts[2] : '');
        if (null === $this->reference) {
            $content = file_get_contents(__DIR__.'/lifecycle-reference.json');
            if (false === $content) {
                throw new RegistryUnavailable('Référence locale de support indisponible.');
            }
            $this->reference = ManifestData::json($content);
        }
        $reference = $this->reference;
        $products = ManifestData::map($reference['products'] ?? null);
        $product = ManifestData::map($products[$technology] ?? null);
        $cycles = ManifestData::map($product['cycles'] ?? null);
        $release = ManifestData::map($cycles[$cycle] ?? null);
        $eol = ManifestData::text($release['eol'] ?? null);
        $source = ManifestData::text($product['source'] ?? null);
        $verifiedAt = ManifestData::text($reference['verifiedAt'] ?? null);
        if (null === $eol || null === $source || null === $verifiedAt) {
            return null;
        }

        return ['eol' => $eol, 'endOfMaintenance' => ManifestData::text($release['endOfMaintenance'] ?? null), 'source' => str_replace('{cycle}', $cycle, $source), 'verifiedAt' => $verifiedAt, 'target' => null, 'precision' => ManifestData::text($product['precision'] ?? null) ?? 'day'];
    }
}
