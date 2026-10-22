<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @internal */
final class ManifestData
{
    /** @return array<array-key, mixed> */
    public static function json(string $content): array
    {
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !str_starts_with(ltrim($content), '{')) {
            throw new \InvalidArgumentException('Le manifeste doit être un objet JSON.');
        }

        return $data;
    }

    /** @return array<array-key, mixed> */
    public static function map(mixed $data): array
    {
        return is_array($data) ? $data : [];
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** @return array{ecosystem: string, name: string, declared: ?string, resolved: ?string, direct: bool, latest: null, latestCompatible: null, updateType: null, source: string, verifiedAt: null, scope: string} */
    public static function dependency(string $ecosystem, string $name, ?string $declared, ?string $resolved, bool $direct, string $source, string $scope): array
    {
        return compact('ecosystem', 'name', 'declared', 'resolved', 'direct', 'source', 'scope') + ['latest' => null, 'latestCompatible' => null, 'updateType' => null, 'verifiedAt' => null];
    }
}
