<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Application\Handler\RefreshIssueHandler;
use App\Application\Message\RefreshIssue;
use App\Application\Service\AuditTrail;
use App\Application\Service\IssueManager;
use App\Application\Service\RepositoryScope;
use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Git\GitProviderFactory;
use App\Infrastructure\Security\SecretCipher;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class IssueManagerTest extends DatabaseTestCase
{
    public function testPreviewCreateOverlapAndRefreshUseOneExternalCreation(): void
    {
        [$first, $second] = $this->findings();
        $client = new MockHttpClient([
            new MockResponse('{"iid":12,"web_url":"https://127.0.0.1/team/app/-/issues/12","state":"opened"}'),
            new MockResponse('{"iid":12,"web_url":"https://127.0.0.1/team/app/-/issues/12","state":"closed"}'),
        ]);
        $manager = $this->manager($client);
        $draft = $manager->preview([$first->id]);
        self::assertStringContainsString('## Contexte', $draft->body);
        self::assertStringContainsString('7.4 → 8.4', $draft->body);
        self::assertStringContainsString('https://www.php.net/supported-versions.php', $draft->body);
        $issue = $manager->create([$first->id], $draft->title, $draft->body, 'admin');
        self::assertSame('open', $issue->status);
        self::assertSame($issue->id, $manager->create([$first->id, $second->id], $draft->title, $draft->body, 'admin')->id);
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame(1, $this->em->getRepository(IssueTracking::class)->count(['repository' => $first->repository]));
        $manager->refresh($issue, 'admin');
        self::assertSame('closed', $issue->status);
        self::assertNotNull($issue->lastSyncedAt);
    }

    public function testUncertainCreationRemainsReservedAndCanBeReconciled(): void
    {
        [$first] = $this->findings();
        $client = new MockHttpClient([
            new MockResponse('private-token failure', ['http_code' => 503]),
            new MockResponse('{"iid":19,"web_url":"https://127.0.0.1/team/app/-/issues/19","state":"opened"}'),
        ]);
        $manager = $this->manager($client);
        $draft = $manager->preview([$first->id]);
        $issue = $manager->create([$first->id], $draft->title, $draft->body, 'admin');
        self::assertSame('unknown', $issue->status);
        self::assertStringNotContainsString('private-token', $issue->lastError ?? '');
        self::assertSame($issue->id, $manager->create([$first->id], $draft->title, $draft->body, 'admin')->id);
        self::assertSame(1, $client->getRequestsCount());
        $manager->refresh($issue, 'admin', '19');
        self::assertSame('19', $issue->number);
        self::assertSame('open', $issue->status);
    }

    public function testForbiddenCreationCanOnlyBeRetriedExplicitly(): void
    {
        [$first] = $this->findings();
        $client = new MockHttpClient([
            new MockResponse('secret', ['http_code' => 403]),
            new MockResponse('{"iid":20,"web_url":"https://127.0.0.1/team/app/-/issues/20","state":"opened"}'),
        ]);
        $manager = $this->manager($client);
        $draft = $manager->preview([$first->id]);
        $issue = $manager->create([$first->id], $draft->title, $draft->body, 'admin');
        self::assertSame('failed', $issue->status);
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame($issue->id, $manager->create([$first->id], $draft->title, $draft->body, 'admin')->id);
        self::assertSame('open', $issue->status);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testReservationIsCommittedBeforeCallingProvider(): void
    {
        [$finding] = $this->findings();
        $this->em->getConnection()->commit();
        $visible = null;
        $client = new MockHttpClient(function () use ($finding, &$visible): MockResponse {
            $observer = \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams());
            try {
                $visible = $observer->fetchOne('SELECT status FROM issue_tracking WHERE repository_id = ?', [$finding->repository->id]);
            } finally {
                $observer->close();
            }

            return new MockResponse('{"iid":21,"web_url":"https://127.0.0.1/team/app/-/issues/21","state":"opened"}');
        });
        $issue = null;
        try {
            $manager = $this->manager($client);
            $draft = $manager->preview([$finding->id]);
            $issue = $manager->create([$finding->id], $draft->title, $draft->body, 'reservation-test');
            self::assertSame('creating', $visible);
            self::assertSame('open', $issue->status);
        } finally {
            if (null !== $issue) {
                $this->em->getConnection()->executeStatement('DELETE FROM audit_event WHERE subject_id = ?', [$issue->id]);
            }
            $this->em->remove($finding->repository->connection);
            $this->em->flush();
        }
    }

    public function testConcurrentCreationCannotCallProvider(): void
    {
        [$finding] = $this->findings();
        $client = new MockHttpClient();
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('issue-create-'.$finding->repository->id);
        self::assertTrue($held->acquire());
        try {
            $manager = $this->manager($client, $locks);
            $draft = $manager->preview([$finding->id]);
            $manager->create([$finding->id], $draft->title, $draft->body, 'admin');
            self::fail('Expected lock contention');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Une création d’issue est déjà en cours.', $error->getMessage());
            self::assertSame(0, $client->getRequestsCount());
        } finally {
            $held->release();
        }
    }

    public function testClosedExactSelectionNeverCreatesDuplicate(): void
    {
        [$finding] = $this->findings();
        $client = new MockHttpClient();
        $manager = $this->manager($client);
        $draft = $manager->preview([$finding->id]);
        $existing = new IssueTracking();
        $existing->repository = $finding->repository;
        $existing->fingerprint = $draft->fingerprint;
        $existing->fingerprints = [$finding->fingerprint];
        $existing->status = 'closed';
        $this->em->persist($existing);
        $this->em->flush();
        self::assertSame($existing->id, $manager->create([$finding->id], $draft->title, $draft->body, 'admin')->id);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testExcludedPatternIsReloadedBeforeCreation(): void
    {
        [$finding] = $this->findings();
        $client = new MockHttpClient();
        $manager = $this->manager($client);
        $draft = $manager->preview([$finding->id]);
        // Model another process changing the persisted scope after the preview.
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET excluded_repositories = ? WHERE id = ?', ['["team/*"]', $finding->repository->connection->id]);
        try {
            $manager->create([$finding->id], $draft->title, $draft->body, 'admin');
            self::fail('Expected withdrawn scope rejection');
        } catch (\DomainException $error) {
            self::assertSame('Cette application ne fait plus partie du périmètre Git actif.', $error->getMessage());
            self::assertSame(0, $client->getRequestsCount());
            self::assertSame(0, $this->em->getRepository(IssueTracking::class)->count(['repository' => $finding->repository]));
        }
    }

    public function testUnmonitoredRepositoryCannotReceiveIssue(): void
    {
        [$finding] = $this->findings();
        $client = new MockHttpClient();
        $manager = $this->manager($client);
        $draft = $manager->preview([$finding->id]);
        $this->em->getConnection()->executeStatement('UPDATE repository SET monitored = FALSE WHERE id = ?', [$finding->repository->id]);
        try {
            $manager->create([$finding->id], $draft->title, $draft->body, 'admin');
            self::fail('Expected unmonitored rejection');
        } catch (\DomainException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testRemovedOrganizationCannotReceiveIssue(): void
    {
        [$finding] = $this->findings();
        $client = new MockHttpClient();
        $manager = $this->manager($client);
        $draft = $manager->preview([$finding->id]);
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET organizations = ? WHERE id = ?', ['["another-team"]', $finding->repository->connection->id]);
        try {
            $manager->create([$finding->id], $draft->title, $draft->body, 'admin');
            self::fail('Expected organization rejection');
        } catch (\DomainException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testInactiveConnectionCannotRefreshAnExistingIssue(): void
    {
        [$finding] = $this->findings();
        $issue = new IssueTracking();
        $issue->repository = $finding->repository;
        $issue->number = '12';
        $issue->status = 'open';
        $this->em->persist($issue);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET active = FALSE WHERE id = ?', [$finding->repository->connection->id]);
        $client = new MockHttpClient();
        try {
            $this->manager($client)->refresh($issue, 'admin');
            self::fail('Expected inactive connection rejection');
        } catch (\DomainException) {
            self::assertSame(0, $client->getRequestsCount());
            self::assertSame('open', $issue->status);
        }
    }

    public function testCrossRepositorySelectionIsRejected(): void
    {
        [$first] = $this->findings();
        [$other] = $this->findings();
        $this->expectException(\InvalidArgumentException::class);
        $this->manager(new MockHttpClient())->preview([$first->id, $other->id]);
    }

    public function testResolvedFindingIsRejected(): void
    {
        [$first] = $this->findings();
        $first->resolvedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        $this->manager(new MockHttpClient())->preview([$first->id]);
    }

    public function testInvalidAndMissingIdentifiersAreDistinct(): void
    {
        $manager = $this->manager(new MockHttpClient());
        try {
            $manager->preview(['not-uuid']);
            self::fail('Expected validation failure');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Identifiant de constat invalide.', $error->getMessage());
        }
        $this->expectException(\OutOfBoundsException::class);
        $manager->preview(['00000000-0000-4000-8000-000000000000']);
    }

    public function testScheduledRefreshIsIdempotentAndNeverCreatesIssue(): void
    {
        [$finding] = $this->findings();
        $issue = new IssueTracking();
        $issue->repository = $finding->repository;
        $issue->fingerprint = 'scheduled';
        $issue->number = '12';
        $issue->status = 'open';
        $this->em->persist($issue);
        $this->em->flush();
        $client = new MockHttpClient(new MockResponse('{"iid":12,"web_url":"https://127.0.0.1/team/app/-/issues/12","state":"closed"}'));
        $handler = new RefreshIssueHandler($this->em, $this->manager($client));
        $message = new RefreshIssue($issue->id);
        $handler($message);
        $handler($message);
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame('closed', $issue->status);
    }

    public function testScheduledQuotaRetryRespectsTransportRetryLimit(): void
    {
        [$finding] = $this->findings();
        $issue = new IssueTracking();
        $issue->repository = $finding->repository;
        $issue->fingerprint = 'quota';
        $issue->number = '12';
        $issue->status = 'open';
        $this->em->persist($issue);
        $this->em->flush();
        $client = new MockHttpClient(new MockResponse('secret', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]));
        $handler = new RefreshIssueHandler($this->em, $this->manager($client));
        try {
            $handler(new RefreshIssue($issue->id));
            self::fail('Expected bounded retry');
        } catch (RecoverableMessageHandlingException $error) {
            self::assertSame(120000, $error->getRetryDelay());
            self::assertFalse($error->forceRetry());
            self::assertNotNull($issue->lastError);
            self::assertStringNotContainsString('secret', $issue->lastError);
        }
    }

    /** @return array{Finding, Finding} */
    private function findings(): array
    {
        $connection = new ProviderConnection();
        $connection->provider = 'gitlab';
        $connection->baseUrl = 'https://127.0.0.1';
        $connection->encryptedToken = (new SecretCipher(base64_encode(str_repeat('k', 32))))->encrypt('private-token');
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = '7';
        $repository->name = 'team/app';
        $this->em->persist($connection);
        $this->em->persist($repository);
        $findings = [];
        foreach (['runtime', 'framework'] as $name) {
            $finding = new Finding();
            $finding->repository = $repository;
            $finding->fingerprint = 'main:'.$name;
            $finding->title = 'Runtime hors support';
            $finding->details = ['current' => '7.4', 'target' => '8.4', 'source' => 'https://www.php.net/supported-versions.php'];
            $this->em->persist($finding);
            $findings[] = $finding;
        }
        $this->em->flush();

        return [$findings[0], $findings[1]];
    }

    private function manager(MockHttpClient $client, ?LockFactory $locks = null): IssueManager
    {
        $factory = new GitProviderFactory($client, new SecretCipher(base64_encode(str_repeat('k', 32))), new GitEndpointPolicy('127.0.0.1', '127.0.0.1'));

        return new IssueManager($this->em, $factory, $locks ?? new LockFactory(new InMemoryStore()), new AuditTrail($this->em), new RepositoryScope());
    }
}
