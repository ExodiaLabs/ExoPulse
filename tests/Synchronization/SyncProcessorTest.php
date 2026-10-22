<?php

declare(strict_types=1);

namespace App\Tests\Synchronization;

use App\Application\Service\RequestJobs;
use App\Application\Service\SyncProcessor;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Infrastructure\Security\SecretCipher;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncProcessorTest extends DatabaseTestCase
{
    public function testDiscoveryIsStableScopedAndIdempotentAndQueuesScans(): void
    {
        $cipher = new SecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $connection = new ProviderConnection();
        $connection->name = 'Synchronization fixture';
        $connection->encryptedToken = $cipher->encrypt('synthetic-test-token');
        $connection->organizations = ['allowed'];
        $connection->excludedRepositories = ['allowed/excluded'];
        $this->em->persist($connection);
        $this->em->flush();
        $calls = 0;
        self::getContainer()->set('exopulse.git_http_client', new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertStringContainsString('/orgs/allowed/repos', $url);

            return new MockResponse(json_encode([
                ['id' => 42, 'full_name' => 'allowed/legacy', 'html_url' => 'https://github.com/allowed/legacy', 'default_branch' => 'main', 'archived' => false],
                ['id' => 43, 'full_name' => 'allowed/excluded', 'html_url' => 'https://github.com/allowed/excluded', 'default_branch' => 'main', 'archived' => false],
                ['id' => 44, 'full_name' => 'allowed/archive', 'html_url' => 'https://github.com/allowed/archive', 'default_branch' => 'main', 'archived' => true],
            ], JSON_THROW_ON_ERROR));
        }));
        $jobs = self::getContainer()->get(RequestJobs::class);
        self::assertInstanceOf(RequestJobs::class, $jobs);
        $first = $jobs->synchronize($connection);
        self::assertSame($first->id, $jobs->synchronize($connection)->id);
        $processor = self::getContainer()->get(SyncProcessor::class);
        self::assertInstanceOf(SyncProcessor::class, $processor);
        $processor->process($first->id);
        self::assertSame('completed', $first->status);
        self::assertSame(2, $this->em->getRepository(Repository::class)->count(['connection' => $connection]));
        self::assertSame(2, $this->em->getRepository(Scan::class)->count([]));
        $repository = $this->em->getRepository(Repository::class)->findOneBy(['connection' => $connection, 'externalId' => '42']);
        self::assertNotNull($repository);
        $identity = $repository->id;
        $processor->process($first->id);
        self::assertSame(1, $calls);
        $second = $jobs->synchronize($connection);
        $processor->process($second->id);
        self::assertSame(2, $calls);
        self::assertSame($identity, $this->em->getRepository(Repository::class)->findOneBy(['connection' => $connection, 'externalId' => '42'])?->id);
        self::assertSame(2, $this->em->getRepository(Scan::class)->count([]));
    }

    public function testUnseenGitlabRepositoriesStayOutsideNumericGroupScope(): void
    {
        $connection = new ProviderConnection();
        $connection->provider = 'gitlab';
        $connection->baseUrl = 'https://gitlab.com';
        $connection->organizations = ['123'];
        $connection->encryptedToken = (new SecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='))->encrypt('synthetic-token');
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = 'former';
        $repository->name = 'previous-group/private';
        $this->em->persist($connection);
        $this->em->persist($repository);
        $this->em->flush();
        self::getContainer()->set('exopulse.git_http_client', new MockHttpClient(new MockResponse('[]')));
        $jobs = self::getContainer()->get(RequestJobs::class);
        self::assertInstanceOf(RequestJobs::class, $jobs);
        $job = $jobs->synchronize($connection);
        $processor = self::getContainer()->get(SyncProcessor::class);
        self::assertInstanceOf(SyncProcessor::class, $processor);
        $processor->process($job->id);
        self::assertFalse($repository->accessible);
        self::assertFalse($repository->monitored);
        $this->expectException(\DomainException::class);
        $jobs->scan($repository);
    }

    public function testNumericGroupChangeDuringEnumerationCannotReactivateFormerRepository(): void
    {
        [$connection, $repository] = $this->numericScopeFixture();
        self::getContainer()->set('exopulse.git_http_client', new MockHttpClient(function (string $method, string $url) use ($connection): MockResponse {
            self::assertStringContainsString('/groups/17/projects', $url);
            $this->changeNumericScope($connection);

            return new MockResponse('[{"id":42,"path_with_namespace":"former-group/private","web_url":"https://gitlab.com/former-group/private","default_branch":"main","archived":false}]');
        }));
        $jobs = self::getContainer()->get(RequestJobs::class);
        self::assertInstanceOf(RequestJobs::class, $jobs);
        $job = $jobs->synchronize($connection);
        $processor = self::getContainer()->get(SyncProcessor::class);
        self::assertInstanceOf(SyncProcessor::class, $processor);
        $processor->process($job->id);
        $this->em->refresh($repository);
        self::assertSame('cancelled', $job->status);
        self::assertSame(0, $job->processed);
        self::assertStringContainsString('nouveau périmètre', $job->lastError ?? '');
        self::assertNotNull($job->finishedAt);
        self::assertNull($connection->nextSyncAt);
        self::assertFalse($repository->monitored);
        self::assertSame(0, $this->em->getRepository(Scan::class)->count([]));
        $this->expectException(\DomainException::class);
        $jobs->scan($repository);
    }

    public function testNumericGroupChangeOnEmptyFinalPageCancelsBeforeUnseenReconciliation(): void
    {
        [$connection, $repository] = $this->numericScopeFixture();
        self::getContainer()->set('exopulse.git_http_client', new MockHttpClient(function () use ($connection): MockResponse {
            $this->changeNumericScope($connection);

            return new MockResponse('[]');
        }));
        $jobs = self::getContainer()->get(RequestJobs::class);
        self::assertInstanceOf(RequestJobs::class, $jobs);
        $job = $jobs->synchronize($connection);
        $processor = self::getContainer()->get(SyncProcessor::class);
        self::assertInstanceOf(SyncProcessor::class, $processor);
        $processor->process($job->id);
        $this->em->refresh($repository);
        self::assertSame('cancelled', $job->status);
        self::assertFalse($repository->monitored);
        // An obsolete empty page cannot assert that the repository has become inaccessible.
        self::assertTrue($repository->accessible);
        self::assertNull($connection->lastSyncedAt);
    }

    /** @return array{ProviderConnection, Repository} */
    private function numericScopeFixture(): array
    {
        $connection = new ProviderConnection();
        $connection->provider = 'gitlab';
        $connection->baseUrl = 'https://gitlab.com';
        $connection->organizations = ['17'];
        $connection->encryptedToken = (new SecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='))->encrypt('synthetic-token');
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = '42';
        $repository->name = 'former-group/private';
        $repository->lastScanAt = new \DateTimeImmutable();
        $this->em->persist($connection);
        $this->em->persist($repository);
        $this->em->flush();

        return [$connection, $repository];
    }

    private function changeNumericScope(ProviderConnection $connection): void
    {
        // DBAL writes simulate an administrator committing a different configuration
        // while the worker still holds the original Doctrine identity map and iterator.
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET organizations = ? WHERE id = ?', ['["99"]', $connection->id]);
        $this->em->getConnection()->executeStatement('UPDATE repository SET monitored = false WHERE connection_id = ?', [$connection->id]);
    }
}
