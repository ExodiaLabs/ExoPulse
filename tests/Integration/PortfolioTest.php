<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Persistence\Portfolio;

final class PortfolioTest extends DatabaseTestCase
{
    public function testFilteringKeepsUnknownSeparateAndBoundsSortAndPagination(): void
    {
        $connection = new ProviderConnection();
        $this->em->persist($connection);
        foreach ([null, 30, 65, 95] as $index => $score) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = (string) $index;
            $repository->name = 'Team/application-'.$index;
            $repository->latestScore = $score;
            $this->em->persist($repository);
        }
        $this->em->flush();
        $portfolio = new Portfolio($this->em);
        $unknown = $portfolio->repositories(status: 'unknown');
        self::assertSame(1, $unknown['total']);
        self::assertNull($unknown['repositories'][0]->latestScore);
        self::assertSame(1, $portfolio->repositories(status: 'critical')['total']);
        self::assertSame(0, $portfolio->repositories(q: "' OR 1=1 --")['total']);
        self::assertSame(1, $portfolio->repositories(sort: 'untrusted SQL', page: 1000)['page']);
        self::assertSame(4, $portfolio->stats()['total']);
    }
}
