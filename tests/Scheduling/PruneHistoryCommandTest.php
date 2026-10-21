<?php

declare(strict_types=1);

namespace App\Tests\Scheduling;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Presentation\Command\PruneHistoryCommand;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneHistoryCommandTest extends DatabaseTestCase
{
    public function testDryRunKeepsEverythingAndExecutionPreservesCurrentAndPendingScans(): void
    {
        $connection = new ProviderConnection();
        $repository = new Repository();
        $repository->connection = $connection;
        $this->em->persist($connection);
        $this->em->persist($repository);
        foreach (['completed', 'partial', 'failed', 'queued', 'running'] as $status) {
            $scan = new Scan();
            $scan->repository = $repository;
            $scan->createdAt = new \DateTimeImmutable('-400 days');
            $scan->status = $status;
            $this->em->persist($scan);
            if ('completed' === $status) {
                $repository->latestScan = $scan;
            }
        }
        $recent = new Scan();
        $recent->repository = $repository;
        $recent->status = 'completed';
        $this->em->persist($recent);
        $this->em->flush();
        $command = self::getContainer()->get(PruneHistoryCommand::class);
        self::assertInstanceOf(PruneHistoryCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(6, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
        self::assertSame(0, $tester->execute(['--execute' => true]));
        self::assertSame(4, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
    }
}
