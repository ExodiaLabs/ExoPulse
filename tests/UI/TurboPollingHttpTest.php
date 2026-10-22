<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Domain\Model\SyncJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class TurboPollingHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
        self::assertResponseRedirects('/');
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testHistoryFrameStopsRequestingRefreshWhenScanCompletes(): void
    {
        $repository = $this->repository();
        $scan = new Scan();
        $scan->repository = $repository;
        $scan->branch = 'synthetic-poll-transition';
        $this->em->persist($scan);
        $this->em->flush();
        $frame = 'scan-history-'.$repository->id;
        $path = '/applications/'.$repository->id.'/history';
        $this->client->request('GET', $path, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'turbo-frame#'.$frame);
        self::assertSelectorExists('#'.$frame.' [data-refresh-active="true"]');
        self::assertSelectorTextContains('#'.$frame, 'En attente');

        $updated = $this->em->find(Scan::class, $scan->id);
        self::assertInstanceOf(Scan::class, $updated);
        $updated->status = 'completed';
        $updated->finishedAt = new \DateTimeImmutable();
        $updated->score = 92;
        $this->em->flush();
        $this->client->request('GET', $path, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#'.$frame.' [data-refresh-active="false"]');
        self::assertSelectorNotExists('#'.$frame.' [data-refresh-active="true"]');
        self::assertSelectorTextContains('#'.$frame, 'Terminée');
        self::assertSelectorTextContains('#'.$frame, '92');
    }

    public function testHistoryFramePaginationPreservesRepositoryAndQuery(): void
    {
        $repository = $this->repository();
        $now = new \DateTimeImmutable();
        for ($index = 0; $index < 27; ++$index) {
            $scan = new Scan();
            $scan->repository = $repository;
            $scan->status = 'completed';
            $scan->branch = 'synthetic-page-'.$index;
            $scan->createdAt = $now->modify('-'.$index.' minutes');
            $scan->finishedAt = $scan->createdAt;
            $this->em->persist($scan);
        }
        $this->em->flush();
        $frame = 'scan-history-'.$repository->id;
        $path = '/applications/'.$repository->id.'/history';
        $crawler = $this->client->request('GET', $path.'?page=1&view=compact', server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '#'.$frame.' tbody tr');
        $next = $crawler->filter('#'.$frame.' nav[aria-label="Pagination"]')->selectLink('2')->link();
        $url = parse_url($next->getUri());
        self::assertIsArray($url);
        self::assertSame($path, $url['path'] ?? null);
        parse_str($url['query'] ?? '', $query);
        self::assertSame('2', $query['page'] ?? null);
        self::assertSame('compact', $query['view'] ?? null);

        $this->client->request('GET', $next->getUri(), server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'turbo-frame#'.$frame);
        self::assertSelectorCount(2, '#'.$frame.' tbody tr');
        self::assertSelectorTextContains('#'.$frame, 'synthetic-page-25');
        self::assertSelectorTextContains('#'.$frame, 'synthetic-page-26');
        self::assertSelectorTextNotContains('#'.$frame, 'synthetic-page-0');
        self::assertSelectorTextContains('#'.$frame.' [aria-current="page"]', '2');
    }

    public function testConnectionActivityFrameReflectsSyncCompletionAndRejectsReader(): void
    {
        $repository = $this->repository();
        $connection = $repository->connection;
        $job = new SyncJob();
        $job->connection = $connection;
        $this->em->persist($job);
        $this->em->flush();
        $frame = 'connection-activity-'.$connection->id;
        $path = '/connections/'.$connection->id;
        $this->client->request('GET', $path, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#'.$frame.' [data-refresh-active="true"]');
        self::assertSelectorTextContains('#'.$frame, 'En attente');

        $updated = $this->em->find(SyncJob::class, $job->id);
        self::assertInstanceOf(SyncJob::class, $updated);
        $updated->status = 'completed';
        $updated->processed = 7;
        $updated->finishedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->client->request('GET', $path, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#'.$frame.' [data-refresh-active="false"]');
        self::assertSelectorTextContains('#'.$frame, 'Terminée');
        self::assertSelectorTextContains('#'.$frame.' tbody tr td:nth-child(3)', '7');

        $provider = self::getContainer()->get('security.user.provider.concrete.administrators');
        self::assertInstanceOf(InMemoryUserProvider::class, $provider);
        $reader = new InMemoryUser('synthetic-turbo-reader', null, ['ROLE_USER']);
        $provider->createUser($reader);
        $this->client->loginUser($reader);
        $this->client->request('GET', $path, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorNotExists('turbo-frame#'.$frame);
    }

    private function repository(): Repository
    {
        $connection = new ProviderConnection();
        $connection->name = 'Synthetic Turbo connection';
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = 'synthetic-turbo-repository';
        $repository->name = 'synthetic/turbo-repository';
        $this->em->persist($connection);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }
}
