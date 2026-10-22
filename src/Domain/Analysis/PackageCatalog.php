<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

interface PackageCatalog
{
    /** @return array{versions: list<string>, source: string, verifiedAt: string} */
    public function versions(string $ecosystem, string $name): array;
}
