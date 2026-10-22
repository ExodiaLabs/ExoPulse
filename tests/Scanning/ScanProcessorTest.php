<?php

declare(strict_types=1);

namespace App\Tests\Scanning;

use App\Application\Handler\AnalyzeRepositoryHandler;
use App\Application\Message\AnalyzeRepository;
use App\Application\Service\AuditTrail;
use App\Application\Service\ConfigurationStore;
use App\Application\Service\FindingReconciler;
use App\Application\Service\RepositoryScope;
use App\Application\Service\ScanProcessor;
use App\Domain\Analysis\AnalysisEngine;
use App\Domain\Analysis\ComposerParser;
use App\Domain\Analysis\HealthPolicy;
use App\Domain\Analysis\JavascriptLockParser;
use App\Domain\Analysis\JavascriptParser;
use App\Domain\Analysis\PackageCatalog;
use App\Domain\Analysis\RuntimeDetector;
use App\Domain\Analysis\SupportCatalog;
use App\Domain\Analysis\VersionComparison;
use App\Domain\Model\AuditEvent;
use App\Domain\Model\Finding;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Git\GitProviderFactory;
use App\Infrastructure\Security\SecretCipher;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ScanProcessorTest extends DatabaseTestCase
{
    /** @var array<string, string> */
    private array $files = [];
    private string $commit = 'commit-a';
    private int $fileReads = 0;
    private int $headStatus = 200;
    private ?string $brokenFile = null;
    private int $retryAfter = 0;
    private bool $registryUnavailable = false;
    private int $registryReads = 0;

    public function testLegacyScanPersistsSnapshotAndFindingAndAudit(): void
    {
        [$repository, $scan] = $this->fixture();
        $this->files['.php-version'] = '7.4';
        $processor = $this->processor();
        (new AnalyzeRepositoryHandler($processor))(new AnalyzeRepository($scan->id));
        self::assertSame('completed', $scan->status);
        self::assertSame('commit-a', $scan->commitHash);
        self::assertSame(100, $scan->coverage);
        self::assertSame(100, $scan->confidence);
        self::assertSame($scan, $repository->latestScan);
        self::assertSame($scan->score, $repository->latestScore);
        self::assertNotEmpty($scan->results['dependencies']);
        $findings = $this->em->getRepository(Finding::class)->findBy(['repository' => $repository]);
        self::assertCount(2, $findings);
        self::assertSame('main', $findings[0]->details['branch']);
        self::assertNotNull($findings[0]->firstSeenAt);
        self::assertNotNull($findings[0]->lastSeenAt);
        self::assertNotNull($this->em->getRepository(AuditEvent::class)->findOneBy(['action' => 'scan.completed', 'subjectId' => $scan->id]));
    }

    public function testDisabledExternalEnrichmentKeepsStaticInventoryWithoutRegistryCalls(): void
    {
        [, $scan] = $this->fixture();
        (new ConfigurationStore($this->em))->save(['externalEnrichment' => 0]);
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame(0, $this->registryReads);
        self::assertNotEmpty($scan->results['dependencies']);
        self::assertSame('partial', $scan->status);
        self::assertNull($scan->score);
        self::assertSame(100, $scan->coverage);
        self::assertSame(0, $scan->confidence);
    }

    public function testSameCommitUsesFileCacheButStillRefreshesCatalog(): void
    {
        [$repository, $first] = $this->fixture();
        $processor = $this->processor();
        $processor->process($first->id);
        $second = $this->newScan($repository);
        $processor->process($second->id);
        self::assertSame(11, $this->fileReads);
        self::assertSame(2, $this->registryReads);
        self::assertSame('completed', $second->status);
        self::assertCount(1, $this->em->getRepository(Finding::class)->findBy(['repository' => $repository]));
    }

    public function testReprocessingTerminalScanIsIdempotent(): void
    {
        [$repository, $scan] = $this->fixture();
        $processor = $this->processor();
        $processor->process($scan->id);
        $firstSeen = $this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository])?->firstSeenAt;
        $processor->process($scan->id);
        self::assertSame(11, $this->fileReads);
        self::assertSame(1, $this->registryReads);
        self::assertSame($firstSeen, $this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository])?->firstSeenAt);
    }

    public function testDisabledConnectionCancelsWithoutNetwork(): void
    {
        [$repository, $scan] = $this->fixture();
        $repository->connection->active = false;
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('cancelled', $scan->status);
        self::assertSame(0, $this->fileReads);
        self::assertNull($repository->latestScan);
    }

    public function testScopeChangedAfterEnqueueCancelsBeforeReadingPrivateRepository(): void
    {
        [$repository, $scan] = $this->fixture();
        $repository->connection->excludedRepositories = ['org/*'];
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('cancelled', $scan->status);
        self::assertSame(0, $this->fileReads);
    }

    public function testUnmonitoredRepositoryCancelsBeforeAnyNetworkRead(): void
    {
        [$repository, $scan] = $this->fixture();
        $repository->monitored = false;
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('cancelled', $scan->status);
        self::assertSame(0, $this->fileReads);
    }

    public function testRepositoryMarkedInaccessibleCannotBeReadBeforeSuccessfulDiscovery(): void
    {
        [$repository, $scan] = $this->fixture();
        $repository->accessible = false;
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('cancelled', $scan->status);
        self::assertSame(0, $this->fileReads);
    }

    public function testArchivedRepositoriesRemainAnalysable(): void
    {
        [$repository, $scan] = $this->fixture();
        $repository->archived = true;
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('completed', $scan->status);
        self::assertTrue($repository->archived);
    }

    public function testIncompleteScanCannotResolvePreviouslyKnownFinding(): void
    {
        [$repository, $first] = $this->fixture();
        $processor = $this->processor();
        $processor->process($first->id);
        $finding = $this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository]);
        self::assertNotNull($finding);
        $this->commit = 'commit-b';
        $this->brokenFile = 'composer.lock';
        $second = $this->newScan($repository);
        $processor->process($second->id);
        self::assertSame('partial', $second->status);
        self::assertNull($second->score);
        self::assertNull($finding->resolvedAt);
        self::assertStringContainsString('composer.lock', $second->lastError ?? '');
        // A failed read must not be cached for this commit.
        $reads = $this->fileReads;
        $this->brokenFile = null;
        $third = $this->newScan($repository);
        $processor->process($third->id);
        self::assertSame($reads + 11, $this->fileReads);
        self::assertSame('completed', $third->status);
    }

    public function testProvisionalPartialScoreCannotHidePreviouslyKnownCriticalFinding(): void
    {
        [$repository, $first] = $this->fixture();
        $this->files['.php-version'] = '7.4';
        $processor = $this->processor();
        $processor->process($first->id);
        $critical = $this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository, 'severity' => 'critical']);
        self::assertNotNull($critical);
        $this->commit = 'commit-runtime-only';
        $this->files = ['.php-version' => '8.4'];
        $second = $this->newScan($repository);
        $processor->process($second->id);
        self::assertSame('partial', $second->status);
        self::assertSame(100, $second->score);
        self::assertSame(50, $second->coverage);
        self::assertNull($repository->latestScore);
        self::assertNull($critical->resolvedAt);
    }

    public function testRegistryOutageDoesNotResolveKnownFinding(): void
    {
        [$repository, $first] = $this->fixture();
        $processor = $this->processor();
        $processor->process($first->id);
        $this->registryUnavailable = true;
        $second = $this->newScan($repository);
        $processor->process($second->id);
        self::assertSame('partial', $second->status);
        self::assertNull($second->score);
        self::assertNull($this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository])?->resolvedAt);
    }

    public function testCompleteScanResolvesOnlyFindingsOnItsBranch(): void
    {
        [$repository, $first] = $this->fixture();
        $processor = $this->processor();
        $processor->process($first->id);
        $release = $this->newScan($repository, 'release');
        $processor->process($release->id);
        self::assertCount(2, $this->em->getRepository(Finding::class)->findBy(['repository' => $repository]));
        $this->commit = 'commit-updated';
        $this->files['composer.lock'] = '{"packages":[{"name":"vendor/package","version":"2.0.0"}]}';
        $updated = $this->newScan($repository);
        $processor->process($updated->id);
        self::assertSame('completed', $updated->status);
        foreach ($this->em->getRepository(Finding::class)->findBy(['repository' => $repository]) as $finding) {
            if ('main' === ($finding->details['branch'] ?? null)) {
                self::assertNotNull($finding->resolvedAt);
            } else {
                self::assertNull($finding->resolvedAt);
            }
        }
    }

    public function testMissingRepositoryIsPersistedAsInaccessibleWithoutRawError(): void
    {
        [$repository, $scan] = $this->fixture();
        $this->headStatus = 404;
        try {
            $this->processor()->process($scan->id);
            self::fail('Expected unrecoverable access error.');
        } catch (UnrecoverableMessageHandlingException $error) {
            self::assertFalse($repository->accessible);
            self::assertSame('failed', $scan->status);
            self::assertNotNull($scan->finishedAt);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-token', $error->getMessage());
        }
    }

    public function testQuotaFailureUsesBoundedDelayedRetryAndPersistsFailure(): void
    {
        [, $scan] = $this->fixture();
        $this->headStatus = 429;
        $this->retryAfter = 60;
        try {
            $this->processor()->process($scan->id);
            self::fail('Expected recoverable rate limit error.');
        } catch (RecoverableMessageHandlingException $error) {
            self::assertFalse($error->forceRetry());
            self::assertSame(60000, $error->getRetryDelay());
            self::assertSame('failed', $scan->status);
            self::assertNull($error->getPrevious());
        }
    }

    public function testInvalidTokenFailsWithoutUnboundedRetriesOrTokenDisclosure(): void
    {
        [, $scan] = $this->fixture();
        $this->headStatus = 401;
        try {
            $this->processor()->process($scan->id);
            self::fail('Expected unrecoverable authentication error.');
        } catch (UnrecoverableMessageHandlingException $error) {
            self::assertSame('failed', $scan->status);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-token', $error->getMessage());
        }
    }

    public function testProviderOutageProducesSanitizedRetryableFailureState(): void
    {
        [, $scan] = $this->fixture();
        $this->headStatus = 503;
        try {
            $this->processor()->process($scan->id);
            self::fail('Expected bounded transport retry.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $error);
            self::assertSame('failed', $scan->status);
            self::assertNull($error->getPrevious());
            self::assertNotNull($scan->finishedAt);
        }
    }

    public function testConcurrentScanIsRetriedAndCannotMutateCurrentRun(): void
    {
        [$repository, $scan] = $this->fixture();
        $locks = new LockFactory(new FlockStore(sys_get_temp_dir()));
        $held = $locks->createLock('scan-repository-'.$repository->id, 3600);
        self::assertTrue($held->acquire());
        try {
            $this->processor()->process($scan->id);
            self::fail('Expected lock contention retry.');
        } catch (RecoverableMessageHandlingException $error) {
            self::assertFalse($error->forceRetry());
            self::assertSame(1000, $error->getRetryDelay());
            self::assertSame('queued', $scan->status);
        } finally {
            $held->release();
        }
    }

    public function testRunningScanResumesWhenNoWorkerOwnsItsLock(): void
    {
        [, $scan] = $this->fixture();
        $scan->status = 'running';
        $this->em->flush();
        $this->processor()->process($scan->id);
        self::assertSame('completed', $scan->status);
    }

    /** @return array{Repository, Scan} */
    private function fixture(): array
    {
        (new ConfigurationStore($this->em))->save(['externalEnrichment' => 1]);
        $cipher = new SecretCipher(base64_encode(str_repeat('k', 32)));
        $connection = new ProviderConnection();
        $connection->encryptedToken = $cipher->encrypt('synthetic-token');
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = '42';
        $repository->name = 'org/legacy';
        $repository->url = 'https://github.com/org/legacy';
        $this->em->persist($connection);
        $this->em->persist($repository);
        $this->files = ['composer.json' => '{"require":{"vendor/package":"^1.0"}}', 'composer.lock' => '{"packages":[{"name":"vendor/package","version":"1.0.0"}]}'];

        return [$repository, $this->newScan($repository)];
    }

    private function newScan(Repository $repository, string $branch = 'main'): Scan
    {
        $scan = new Scan();
        $scan->repository = $repository;
        $scan->branch = $branch;
        $this->em->persist($scan);
        $this->em->flush();

        return $scan;
    }

    private function processor(): ScanProcessor
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/repositories/42')) {
                return new MockResponse('{"full_name":"org/legacy"}');
            }
            if (str_contains($url, '/commits/')) {
                return new MockResponse(json_encode(['sha' => $this->commit], JSON_THROW_ON_ERROR), ['http_code' => $this->headStatus, 'response_headers' => $this->retryAfter > 0 ? ['Retry-After: '.$this->retryAfter] : []]);
            }
            ++$this->fileReads;
            $path = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            if ($path === $this->brokenFile) {
                return new MockResponse('private-token upstream failure', ['http_code' => 503]);
            }
            if (!isset($this->files[$path])) {
                return new MockResponse('{}', ['http_code' => 404]);
            }

            return new MockResponse(json_encode(['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode($this->files[$path])], JSON_THROW_ON_ERROR));
        });
        $provider = new GitProviderFactory($client, new SecretCipher(base64_encode(str_repeat('k', 32))), new GitEndpointPolicy('github.com,api.github.com', ''));
        $packages = new class($this) implements PackageCatalog {
            public function __construct(private ScanProcessorTest $test)
            {
            }

            public function versions(string $ecosystem, string $name): array
            {
                return $this->test->catalogVersions();
            }
        };
        $support = new class implements SupportCatalog {
            public function lookup(string $technology, string $version): ?array
            {
                if ('PHP' !== $technology) {
                    return null;
                }

                return ['eol' => str_starts_with($version, '8.') ? '2099-12-31' : '2022-11-28', 'endOfMaintenance' => str_starts_with($version, '8.') ? '2098-12-31' : '2021-11-28', 'source' => 'https://example.test/support', 'verifiedAt' => '2026-10-21T00:00:00+00:00', 'target' => '8.4.0'];
            }
        };
        $engine = new AnalysisEngine(new ComposerParser(), new JavascriptParser(new JavascriptLockParser()), new RuntimeDetector(), $packages, $support, new HealthPolicy(), new VersionComparison());

        return new ScanProcessor($this->em, $provider, $engine, new FindingReconciler($this->em), new LockFactory(new FlockStore(sys_get_temp_dir())), new ArrayAdapter(), new ConfigurationStore($this->em), new AuditTrail($this->em), new RepositoryScope());
    }

    /** @return array{versions: list<string>, source: string, verifiedAt: string} */
    public function catalogVersions(): array
    {
        ++$this->registryReads;
        if ($this->registryUnavailable) {
            throw new \RuntimeException('Synthetic registry outage.');
        }

        return ['versions' => ['1.0.0', '2.0.0'], 'source' => 'https://example.test/registry', 'verifiedAt' => '2026-10-21T00:00:00+00:00'];
    }
}
