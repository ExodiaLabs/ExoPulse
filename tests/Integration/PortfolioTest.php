<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Service\ConfigurationStore;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
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
            $repository->lastScanAt = new \DateTimeImmutable();
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

    public function testStaleScoresAreUnknownAndCustomFreshnessThresholdTakesEffect(): void
    {
        $connection = new ProviderConnection();
        $this->em->persist($connection);
        foreach (['old' => '-8 days', 'recent' => '-2 days', 'missing' => null] as $name => $date) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = $name;
            $repository->latestScore = 95;
            $repository->lastScanAt = null === $date ? null : new \DateTimeImmutable($date);
            $this->em->persist($repository);
        }
        $this->em->flush();
        $store = new ConfigurationStore($this->em);
        $portfolio = new Portfolio($this->em, $store);
        self::assertSame(1, $portfolio->repositories(status: 'healthy')['total']);
        self::assertSame(2, $portfolio->repositories(status: 'unknown')['total']);
        self::assertSame(2, $portfolio->stats()['stale']);
        self::assertSame(3, $portfolio->stats()['total']);
        $store->save(array_replace(ConfigurationStore::DEFAULTS, ['staleDays' => 1]));
        $this->em->flush();
        self::assertSame(0, $portfolio->repositories(status: 'healthy')['total']);
        self::assertSame(3, $portfolio->repositories(status: 'stale')['total']);
        foreach ($portfolio->repositories()['repositories'] as $repository) {
            self::assertSame(95, $repository->latestScore);
        }
    }

    public function testTrendExcludesPartialAndFailedSnapshots(): void
    {
        $connection = new ProviderConnection();
        $repository = new Repository();
        $repository->connection = $connection;
        $this->em->persist($connection);
        $this->em->persist($repository);
        foreach (['completed' => 60, 'partial' => 100, 'failed' => 100] as $status => $score) {
            $scan = new Scan();
            $scan->repository = $repository;
            $scan->status = $status;
            $scan->score = $score;
            $this->em->persist($scan);
        }
        $this->em->flush();
        $trend = (new Portfolio($this->em))->trend();
        self::assertCount(1, $trend);
        self::assertSame(60, $trend[0]['score']);
        self::assertSame(1, $trend[0]['count']);
    }
}
