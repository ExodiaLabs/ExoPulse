<?php

declare(strict_types=1);

namespace App\Domain\Git;

interface GitReader
{
    public function test(): void;

    /**
     * @param list<string> $scopes
     *
     * @return iterable<RepositoryMetadata>
     */
    public function repositories(array $scopes = []): iterable;

    /** @return list<string> */
    public function branches(string $externalId): array;

    public function head(string $externalId, string $branch): string;

    public function file(string $externalId, string $path, string $ref): ?string;
}
