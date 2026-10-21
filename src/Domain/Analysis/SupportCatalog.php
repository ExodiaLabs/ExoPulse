<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

interface SupportCatalog
{
    /** @return array{eol: ?string, endOfMaintenance: ?string, source: string, verifiedAt: string, target: ?string}|null */
    public function lookup(string $technology, string $version): ?array;
}
