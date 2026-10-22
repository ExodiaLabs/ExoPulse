<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Message\AnalyzeRepository;
use App\Application\Message\SynchronizeConnection;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Domain\Model\SyncJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RequestJobs
{
    public function __construct(private EntityManagerInterface $em, private MessageBusInterface $bus, private LockFactory $locks, private AuditTrail $audit, private RepositoryScope $scope)
    {
    }

    public function synchronize(ProviderConnection $connection, string $actor = 'system'): SyncJob
    {
        if (!$connection->active) {
            throw new \DomainException('Cette connexion est désactivée.');
        }
        $lock = $this->locks->createLock('enqueue-sync-'.$connection->id);
        $lock->acquire(true);
        try {
            return $this->em->wrapInTransaction(function () use ($connection, $actor): SyncJob {
                $existing = $this->em->getRepository(SyncJob::class)->findOneBy(['connection' => $connection, 'status' => ['queued', 'running', 'retrying']]);
                if (null !== $existing) {
                    return $existing;
                }
                $job = new SyncJob();
                $job->connection = $connection;
                $this->em->persist($job);
                $this->audit->record('sync.requested', $connection->id, $actor);
                $this->em->flush();
                $this->bus->dispatch(new SynchronizeConnection($job->id));

                return $job;
            });
        } finally {
            $lock->release();
        }
    }

    public function scan(Repository $repository, string $actor = 'system', ?string $branch = null): Scan
    {
        if (!$repository->connection->active || !$this->scope->includes($repository->connection, $repository->name) || !$repository->monitored || !$repository->accessible) {
            throw new \DomainException('Cette application est inaccessible, exclue ou sa connexion est désactivée. Synchronisez la connexion pour vérifier son périmètre.');
        }
        $branch ??= $this->branches($repository)[0];
        if ('' === $branch || mb_strlen($branch) > 255 || preg_match('/[\x00-\x1f\x7f]/', $branch)) {
            throw new \DomainException('La branche demandée est invalide.');
        }
        $lock = $this->locks->createLock('enqueue-scan-'.$repository->id.'-'.$branch);
        $lock->acquire(true);
        try {
            return $this->em->wrapInTransaction(function () use ($repository, $actor, $branch): Scan {
                $existing = $this->em->getRepository(Scan::class)->findOneBy(['repository' => $repository, 'branch' => $branch, 'status' => ['queued', 'running', 'retrying']]);
                if (null !== $existing) {
                    return $existing;
                }
                $scan = new Scan();
                $scan->repository = $repository;
                $scan->branch = $branch;
                $this->em->persist($scan);
                $this->audit->record('scan.requested', $repository->id, $actor);
                $this->em->flush();
                $this->bus->dispatch(new AnalyzeRepository($scan->id));

                return $scan;
            });
        } finally {
            $lock->release();
        }
    }

    /** @return non-empty-list<string> */
    public function branches(Repository $repository): array
    {
        $branches = array_values(array_unique(array_filter(array_map('trim', explode(',', $repository->connection->branch ?? '')))));

        return [] === $branches ? [$repository->defaultBranch] : $branches;
    }
}
