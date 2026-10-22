<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

interface OfflineSupportCatalog extends SupportCatalog
{
    /** @return array{eol: ?string, endOfMaintenance: ?string, source: string, verifiedAt: string, target: ?string, precision?: string}|null */
    public function lookupOffline(string $technology, string $version): ?array;
}
