<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Persistence\Portfolio;

final class PortfolioCapacityTest extends DatabaseTestCase
{
    public function testFiveHundredRepositoriesRemainPaginatedAndIsolatedByConnection(): void
    {
        $connections = [];
        for ($instance = 0; $instance < 5; ++$instance) {
            $connection = new ProviderConnection();
            $connection->name = 'Synthetic instance '.$instance;
            $this->em->persist($connection);
            $connections[] = $connection;
            for ($index = 0; $index < 100; ++$index) {
                $repository = new Repository();
                $repository->connection = $connection;
                // External IDs intentionally collide between provider connections.
                $repository->externalId = (string) $index;
                $repository->name = sprintf('team-%d/application-%03d', $instance, $index);
                $this->em->persist($repository);
            }
        }
        $this->em->flush();
        $portfolio = new Portfolio($this->em);
        $last = $portfolio->repositories(page: 20);
        self::assertSame(500, $last['total']);
        self::assertSame(20, $last['pages']);
        self::assertCount(25, $last['repositories']);
        self::assertSame('team-4/application-099', $last['repositories'][24]->name);
        $scoped = $portfolio->repositories(connection: $connections[1]->id);
        self::assertSame(100, $scoped['total']);
        self::assertSame(4, $scoped['pages']);
        foreach ($scoped['repositories'] as $repository) {
            self::assertSame($connections[1]->id, $repository->connection->id);
        }
    }
}
