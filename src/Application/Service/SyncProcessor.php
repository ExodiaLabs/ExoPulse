<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Git\ProviderException;
use App\Domain\Model\Repository;
use App\Domain\Model\SyncJob;
use App\Infrastructure\Git\GitProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final readonly class SyncProcessor
{
    public function __construct(private EntityManagerInterface $em, private GitProviderFactory $providers, private RequestJobs $jobs, private RepositoryScope $scope, private LockFactory $locks, private AuditTrail $audit, private ConfigurationStore $configuration)
    {
    }

    public function process(string $jobId): void
    {
        $job = $this->em->find(SyncJob::class, $jobId);
        if (null === $job || in_array($job->status, ['completed', 'partial', 'cancelled'], true)) {
            return;
        }
        $connection = $job->connection;
        $lock = $this->locks->createLock('sync-'.$connection->id);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Une synchronisation est déjà en cours.', retryDelay: 1000, forceRetry: false);
        }
        try {
            $this->em->refresh($job);
            if (in_array($job->status, ['completed', 'partial', 'cancelled'], true)) {
                return;
            }
            $this->em->refresh($connection);
            if (!$connection->active) {
                $job->status = 'cancelled';
                $job->finishedAt = new \DateTimeImmutable();
                $this->em->flush();

                return;
            }
            $organizations = $connection->organizations;
            $job->status = 'running';
            $job->processed = $job->failed = 0;
            $job->lastError = null;
            $job->finishedAt = null;
            $this->em->flush();
            $seen = [];
            $cutoff = new \DateTimeImmutable('-'.$this->configuration->values()['frequencyHours'].' hours');
            foreach ($this->providers->forConnection($connection)->repositories($organizations) as $metadata) {
                if ($this->cancelIfScopeChanged($job, $organizations)) {
                    return;
                }
                if (isset($seen[$metadata->externalId])) {
                    continue;
                }
                $seen[$metadata->externalId] = true;
                if ('' === $metadata->externalId || strlen($metadata->externalId) > 255 || mb_strlen($metadata->name) > 255 || mb_strlen($metadata->defaultBranch) > 255) {
                    ++$job->failed;
                    continue;
                }
                $repository = $this->em->getRepository(Repository::class)->findOneBy(['connection' => $connection, 'externalId' => $metadata->externalId]);
                $included = $this->scope->includes($connection, $metadata->name);
                if (!$included && null === $repository) {
                    continue;
                }
                $repository ??= new Repository();
                $repository->connection = $connection;
                $repository->externalId = $metadata->externalId;
                if ($repository->defaultBranch !== $metadata->defaultBranch && null === $connection->branch) {
                    $repository->latestScan = null;
                    $repository->latestScore = null;
                    $repository->lastScanAt = null;
                    $repository->confidence = $repository->coverage = 0;
                }
                $repository->name = $metadata->name;
                $repository->url = $metadata->url;
                $repository->defaultBranch = $metadata->defaultBranch;
                $repository->archived = $metadata->archived;
                $repository->accessible = true;
                $repository->monitored = $included;
                $this->em->persist($repository);
                $this->em->flush();
                ++$job->processed;
                if ($included && '' !== $repository->defaultBranch && (null === $repository->lastScanAt || $repository->lastScanAt < $cutoff)) {
                    foreach ($this->jobs->branches($repository) as $branch) {
                        $this->jobs->scan($repository, branch: $branch);
                    }
                }
            }
            if ($this->cancelIfScopeChanged($job, $organizations)) {
                return;
            }
            // A partial API response never marks unseen repositories inaccessible.
            foreach ($this->em->getRepository(Repository::class)->findBy(['connection' => $connection]) as $repository) {
                $repository->accessible = isset($seen[$repository->externalId]);
                $repository->monitored = isset($seen[$repository->externalId]) && $this->scope->includes($connection, $repository->name);
            }
            $connection->lastSyncedAt = new \DateTimeImmutable();
            $connection->nextSyncAt = $connection->lastSyncedAt->modify('+'.$connection->frequencyHours.' hours');
            $connection->status = 'connected';
            $connection->lastError = null;
            $job->status = 0 === $job->failed ? 'completed' : 'partial';
            $job->lastError = $job->failed > 0 ? 'Certaines métadonnées de dépôts ne sont pas interprétables.' : null;
            $job->finishedAt = new \DateTimeImmutable();
            $this->audit->record('sync.completed', $connection->id);
            $this->em->flush();
        } catch (\Throwable $error) {
            $safe = $error instanceof ProviderException ? $error->getMessage() : 'La synchronisation a échoué. Vérifiez la connexion et le worker.';
            if ($this->em->isOpen()) {
                $job->status = 'failed';
                $job->lastError = $safe;
                $job->finishedAt = new \DateTimeImmutable();
                $connection->status = 'error';
                $connection->lastError = $safe;
                $this->em->flush();
            }
            if ($error instanceof ProviderException && in_array($error->status, [401, 403], true) && null === $error->retryAfter) {
                throw new UnrecoverableMessageHandlingException($safe);
            }
            throw new RecoverableMessageHandlingException($safe, retryDelay: $error instanceof ProviderException && null !== $error->retryAfter ? max(1000, min(86400000, $error->retryAfter * 1000)) : null, forceRetry: false);
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $organizations */
    private function cancelIfScopeChanged(SyncJob $job, array $organizations): bool
    {
        $connection = $job->connection;
        $this->em->refresh($connection);
        if ($connection->active && $connection->organizations === $organizations) {
            return false;
        }
        $job->status = 'cancelled';
        $job->finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $job->lastError = !$connection->active
            ? 'La connexion a été désactivée pendant la synchronisation.'
            : 'Les groupes ou organisations ont changé pendant la synchronisation. Relancez la découverte sur le nouveau périmètre.';
        if ($connection->organizations !== $organizations) {
            $connection->nextSyncAt = null;
            // Membership inferred from the old enumeration cannot authorize future jobs.
            $this->em->createQueryBuilder()->update(Repository::class, 'r')->set('r.monitored', 'false')
                ->where('r.connection = :connection')->setParameter('connection', $connection)->getQuery()->execute();
        }
        $this->audit->record('sync.cancelled', $job->id);
        $this->em->flush();

        return true;
    }
}
