<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\ProviderConnection;

final class RepositoryScope
{
    public function includes(ProviderConnection $connection, string $name): bool
    {
        if (!$this->organizationIncludes($connection, $name)) {
            return false;
        }

        return ([] === $connection->includedRepositories || $this->matches($connection->includedRepositories, $name)) && !$this->matches($connection->excludedRepositories, $name);
    }

    /** @param list<string> $patterns */
    private function matches(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch(mb_strtolower($pattern), mb_strtolower($name))) {
                return true;
            }
        }

        return false;
    }

    private function organizationIncludes(ProviderConnection $connection, string $name): bool
    {
        if ([] === $connection->organizations) {
            return true;
        }
        $name = mb_strtolower($name);
        foreach ($connection->organizations as $organization) {
            $organization = mb_strtolower(trim($organization, " /\t\r\n"));
            // Numeric GitLab group IDs cannot be inferred from a repository path.
            // Discovery's monitored flag remains the authoritative membership check.
            if ('gitlab' === $connection->provider && ctype_digit($organization)) {
                return true;
            }
            if ('' !== $organization && str_starts_with($name, $organization.'/')) {
                if ('gitlab' === $connection->provider || !str_contains(substr($name, strlen($organization) + 1), '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
