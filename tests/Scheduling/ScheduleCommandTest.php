<?php

declare(strict_types=1);

namespace App\Tests\Scheduling;

use App\Application\Message\RefreshIssue;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Domain\Model\SyncJob;
use App\Presentation\Command\ScheduleCommand;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ScheduleCommandTest extends DatabaseTestCase
{
    public function testTwoTicksDoNotDuplicateJobsAndEachConfiguredBranchIsQueued(): void
    {
        $connection = new ProviderConnection();
        $connection->branch = 'main, develop';
        $repository = new Repository();
        $repository->connection = $connection;
        $this->em->persist($connection);
        $this->em->persist($repository);
        $this->em->flush();
        $command = self::getContainer()->get(ScheduleCommand::class);
        self::assertInstanceOf(ScheduleCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(1, $this->em->getRepository(SyncJob::class)->count(['connection' => $connection]));
        self::assertSame(2, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(1, $this->em->getRepository(SyncJob::class)->count(['connection' => $connection]));
        self::assertSame(2, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
    }

    public function testRecentFailureAndInactiveConnectionAreNotRepeatedEveryMinute(): void
    {
        $connection = new ProviderConnection();
        $connection->nextSyncAt = new \DateTimeImmutable('+1 day');
        $repository = new Repository();
        $repository->connection = $connection;
        $failed = new Scan();
        $failed->repository = $repository;
        $failed->branch = 'main';
        $failed->status = 'failed';
        $disabled = new ProviderConnection();
        $disabled->active = false;
        $inactiveRepository = new Repository();
        $inactiveRepository->connection = $disabled;
        foreach ([$connection, $repository, $failed, $disabled, $inactiveRepository] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $command = self::getContainer()->get(ScheduleCommand::class);
        self::assertInstanceOf(ScheduleCommand::class, $command);
        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertSame(1, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
        self::assertSame(0, $this->em->getRepository(Scan::class)->count(['repository' => $inactiveRepository]));
        self::assertSame(0, $this->em->getRepository(SyncJob::class)->count(['connection' => $disabled]));
    }

    public function testIssueRefreshIsQueuedOnceWithoutPretendingItWasVerified(): void
    {
        $connection = new ProviderConnection();
        $connection->nextSyncAt = new \DateTimeImmutable('+1 day');
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->accessible = false;
        $issue = new IssueTracking();
        $issue->repository = $repository;
        $issue->number = '42';
        $issue->status = 'open';
        $verifiedAt = new \DateTimeImmutable('yesterday 12:00:00');
        $issue->lastSyncedAt = $verifiedAt;
        foreach ([$connection, $repository, $issue] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $command = self::getContainer()->get(ScheduleCommand::class);
        self::assertInstanceOf(ScheduleCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, (new CommandTester($command))->execute([]));
        $stored = $this->em->find(IssueTracking::class, $issue->id);
        self::assertInstanceOf(IssueTracking::class, $stored);
        self::assertEquals($verifiedAt, $stored->lastSyncedAt);
        self::assertGreaterThan(new \DateTimeImmutable('+5 hours'), $stored->nextRefreshAt);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $matching = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshIssue && $message->issueId === $issue->id) {
                ++$matching;
            }
        }
        self::assertSame(1, $matching);
    }

    public function testRemovedScopeDoesNotBlockOtherRepositories(): void
    {
        $connection = new ProviderConnection();
        $connection->organizations = ['allowed'];
        $connection->nextSyncAt = new \DateTimeImmutable('+1 day');
        $this->em->persist($connection);
        $repositories = [];
        foreach (['removed/api', 'allowed/api', 'allowed/unmonitored'] as $name) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->name = $name;
            $repository->externalId = $name;
            $repository->monitored = 'allowed/unmonitored' !== $name;
            $repositories[$name] = $repository;
            $this->em->persist($repository);
        }
        $this->em->flush();
        $command = self::getContainer()->get(ScheduleCommand::class);
        self::assertInstanceOf(ScheduleCommand::class, $command);
        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertSame(0, $this->em->getRepository(Scan::class)->count(['repository' => $repositories['removed/api']]));
        self::assertSame(1, $this->em->getRepository(Scan::class)->count(['repository' => $repositories['allowed/api']]));
        self::assertSame(0, $this->em->getRepository(Scan::class)->count(['repository' => $repositories['allowed/unmonitored']]));
    }
}
