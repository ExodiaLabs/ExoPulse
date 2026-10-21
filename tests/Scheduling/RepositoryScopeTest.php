<?php

declare(strict_types=1);

namespace App\Tests\Scheduling;

use App\Application\Service\RepositoryScope;
use App\Domain\Model\ProviderConnection;
use PHPUnit\Framework\TestCase;

final class RepositoryScopeTest extends TestCase
{
    public function testGithubOwnerAndIncludeExcludePatternsAllApply(): void
    {
        $connection = new ProviderConnection();
        $connection->organizations = ['allowed'];
        $connection->includedRepositories = ['allowed/api-*'];
        $connection->excludedRepositories = ['*/api-private'];
        $scope = new RepositoryScope();
        self::assertTrue($scope->includes($connection, 'ALLOWED/api-public'));
        self::assertFalse($scope->includes($connection, 'allowed/api-private'));
        self::assertFalse($scope->includes($connection, 'allowed/web'));
        self::assertFalse($scope->includes($connection, 'removed/api-public'));
        self::assertFalse($scope->includes($connection, 'allowed/subgroup/api-public'));
    }

    public function testGitlabNamedGroupsIncludeSubgroupsButNotSimilarPrefixes(): void
    {
        $connection = new ProviderConnection();
        $connection->provider = 'gitlab';
        $connection->organizations = ['team/platform'];
        $scope = new RepositoryScope();
        self::assertTrue($scope->includes($connection, 'team/platform/api'));
        self::assertTrue($scope->includes($connection, 'team/platform/subgroup/api'));
        self::assertFalse($scope->includes($connection, 'team/platform-other/api'));
        self::assertFalse($scope->includes($connection, 'team/removed/api'));
        $connection->organizations = ['123'];
        self::assertTrue($scope->includes($connection, 'group-with-numeric-id/api'));
        $connection->excludedRepositories = ['*/api'];
        self::assertFalse($scope->includes($connection, 'group-with-numeric-id/api'));
    }
}
