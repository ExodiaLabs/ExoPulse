<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Analysis\AnalysisEngine;
use App\Domain\Analysis\AnalysisResult;
use App\Domain\Git\ProviderException;
use App\Domain\Model\Scan;
use App\Infrastructure\Git\GitProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ScanProcessor
{
    private const FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', '.nvmrc', '.node-version', '.php-version', 'Dockerfile'];

    public function __construct(
        private EntityManagerInterface $em,
        private GitProviderFactory $providers,
        private AnalysisEngine $engine,
        private FindingReconciler $findings,
        private LockFactory $locks,
        private CacheInterface $cache,
        private ConfigurationStore $configuration,
        private AuditTrail $audit,
        private RepositoryScope $scope,
    ) {
    }

    public function process(string $scanId): void
    {
        $scan = $this->em->find(Scan::class, $scanId);
        if (null === $scan || in_array($scan->status, ['completed', 'success', 'partial', 'cancelled'], true)) {
            return;
        }
        $lock = $this->locks->createLock('scan-repository-'.$scan->repository->id, 3600);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Une analyse de cette application est déjà en cours.', retryDelay: 1000, forceRetry: false);
        }
        try {
            // Re-read after acquiring the shared repository lock: another worker may have finished meanwhile.
            $this->em->refresh($scan);
            if (in_array($scan->status, ['completed', 'success', 'partial', 'cancelled'], true)) {
                return;
            }
            $this->em->refresh($scan->repository);
            $this->em->refresh($scan->repository->connection);
            if (!$scan->repository->connection->active || !$scan->repository->accessible || !$scan->repository->monitored || !$this->scope->includes($scan->repository->connection, $scan->repository->name)) {
                $scan->status = 'cancelled';
                $scan->lastError = 'Cette application est désactivée, inaccessible ou hors du périmètre autorisé.';
                $scan->finishedAt = $this->now();
                $this->audit->record('scan.cancelled', $scan->id);
                $this->em->flush();

                return;
            }
            $scan->status = 'running';
            $scan->lastError = null;
            $scan->finishedAt = null;
            $this->em->flush();
            $provider = $this->providers->forConnection($scan->repository->connection);
            $scan->commitHash = $provider->head($scan->repository->externalId, $scan->branch);
            if ('' === $scan->commitHash) {
                throw new ProviderException('Commit Git indisponible.');
            }
            $fileErrors = [];
            /** @var array<string, string> $files */
            $files = $this->cache->get('exopulse_files_'.hash('sha256', $scan->repository->id.'|'.$scan->commitHash), function (ItemInterface $item) use ($provider, $scan, $lock, &$fileErrors): array {
                $item->expiresAfter(30 * 86400);
                $files = [];
                foreach (self::FILES as $path) {
                    $lock->refresh();
                    try {
                        $content = $provider->file($scan->repository->externalId, $path, (string) $scan->commitHash);
                        if (null !== $content) {
                            if (strlen($content) > 2 * 1024 * 1024) {
                                $fileErrors[] = $path.' : fichier trop volumineux.';
                            } else {
                                $files[$path] = $content;
                            }
                        }
                    } catch (ProviderException $error) {
                        if (null !== $error->retryAfter || in_array($error->status, [401, 403, 429], true)) {
                            throw $error;
                        }
                        $fileErrors[] = $path.' : lecture indisponible, analyse partielle.';
                    }
                }
                if ([] !== $fileErrors) {
                    $item->expiresAfter(0);
                }

                return $files;
            });
            $configuration = $this->configuration->values();
            $weights = array_intersect_key($configuration, array_flip(['end_of_support', 'end_of_maintenance', 'major', 'minor', 'patch']));
            $result = $this->engine->analyse($files, 1 === $configuration['externalEnrichment'], $weights);
            if ([] !== $fileErrors) {
                $result = new AnalysisResult($result->technologies, $result->dependencies, $result->findings, null, min(75, $result->confidence), min(75, $result->coverage), array_merge($result->errors, $fileErrors));
            }
            $lock->refresh();
            $this->complete($scan, $result);
        } catch (ProviderException $error) {
            $scan->status = 'failed';
            $scan->lastError = match ($error->status) {
                401, 403 => 'Accès Git refusé ou quota épuisé.',
                404 => 'Dépôt ou branche inaccessible.',
                429 => 'Quota Git épuisé ; analyse différée.',
                default => 'Fournisseur Git indisponible ; analyse interrompue.',
            };
            if (404 === $error->status) {
                $scan->repository->accessible = false;
            }
            $scan->finishedAt = $this->now();
            $this->audit->record('scan.failed', $scan->id);
            $this->em->flush();
            if (null !== $error->retryAfter || 429 === $error->status) {
                throw new RecoverableMessageHandlingException($scan->lastError, retryDelay: max(1000, min(86400000, ($error->retryAfter ?? 60) * 1000)), forceRetry: false);
            }
            if (in_array($error->status, [401, 403, 404], true)) {
                throw new UnrecoverableMessageHandlingException($scan->lastError);
            }
            throw new \RuntimeException($scan->lastError);
        } catch (\Throwable) {
            $scan->status = 'failed';
            $scan->lastError = 'Analyse interrompue ; réessai nécessaire.';
            $scan->finishedAt = $this->now();
            $this->audit->record('scan.failed', $scan->id);
            $this->em->flush();
            throw new \RuntimeException($scan->lastError);
        } finally {
            $lock->release();
        }
    }

    private function complete(Scan $scan, AnalysisResult $result): void
    {
        $complete = [] === $result->errors && 100 === $result->coverage && 100 === $result->confidence;
        $scan->status = $complete ? 'completed' : 'partial';
        $scan->results = ['technologies' => $result->technologies, 'dependencies' => $result->dependencies, 'findings' => $result->findings, 'errors' => $result->errors];
        $scan->score = $result->score;
        $scan->confidence = $result->confidence;
        $scan->coverage = $result->coverage;
        $scan->lastError = [] === $result->errors ? null : implode(' ', $result->errors);
        $scan->finishedAt = $this->now();
        $repository = $scan->repository;
        $repository->accessible = true;
        $repository->lastCommit = $scan->commitHash;
        $repository->lastScanAt = $scan->finishedAt;
        $repository->latestScan = $scan;
        $repository->latestScore = $complete ? $result->score : null;
        $repository->confidence = $result->confidence;
        $repository->coverage = $result->coverage;
        $this->findings->reconcile($scan, $result, $complete);
        $this->audit->record('scan.'.$scan->status, $scan->id);
        $this->em->flush();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
