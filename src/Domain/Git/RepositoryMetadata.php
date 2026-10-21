<?php

declare(strict_types=1);

namespace App\Domain\Git;

final readonly class RepositoryMetadata
{
    public function __construct(public string $externalId, public string $name, public string $url, public string $defaultBranch, public bool $archived)
    {
    }
}
