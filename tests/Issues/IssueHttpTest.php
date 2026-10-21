<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Git\GitProviderFactory;
use App\Infrastructure\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class IssueHttpTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testAdminPreviewRendersEditableDraftAndRequiresCsrfForEveryWrite(): void
    {
        $finding = $this->finding();
        foreach (['/findings/issues/preview', '/findings/issues', '/issues/'.$finding->id.'/refresh'] as $path) {
            $this->client->request('POST', $path);
            self::assertResponseStatusCodeSame(403);
        }
        $crawler = $this->client->request('GET', '/applications/'.$finding->repository->id);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/findings/issues/preview"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => [$finding->id]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/findings/issues"] textarea[name="body"]');
        self::assertSelectorTextContains('textarea[name="body"]', '## Critères');
        self::assertSelectorExists('input[name="findings[]"]');
    }

    public function testConfirmedFormCreatesAndRefreshesExternalIssue(): void
    {
        $cipher = new SecretCipher(base64_encode(str_repeat('h', 32)));
        $client = new MockHttpClient([
            new MockResponse('{"iid":51,"web_url":"https://127.0.0.1/team/app/-/issues/51","state":"opened"}'),
            new MockResponse('{"iid":51,"web_url":"https://127.0.0.1/team/app/-/issues/51","state":"closed"}'),
        ]);
        self::getContainer()->set(GitProviderFactory::class, new GitProviderFactory($client, $cipher, new GitEndpointPolicy('127.0.0.1', '127.0.0.1')));
        $finding = $this->finding();
        $finding->repository->connection->provider = 'gitlab';
        $finding->repository->connection->baseUrl = 'https://127.0.0.1';
        $finding->repository->connection->encryptedToken = $cipher->encrypt('synthetic-issue-token');
        $this->em->flush();
        $crawler = $this->client->request('GET', '/applications/'.$finding->repository->id);
        $token = $crawler->filter('form[action="/findings/issues/preview"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => [$finding->id]]);
        $this->client->submitForm('Confirmer la création manuelle');
        self::assertResponseRedirects('/applications/'.$finding->repository->id, 303);
        $issue = $this->em->getRepository(IssueTracking::class)->findOneBy(['repository' => $finding->repository]);
        self::assertInstanceOf(IssueTracking::class, $issue);
        self::assertSame('51', $issue->number);
        self::assertSame('open', $issue->status);
        $this->client->followRedirect();
        $this->client->submitForm('Actualiser le statut');
        self::assertResponseRedirects('/applications/'.$finding->repository->id, 303);
        $updated = $this->em->find(IssueTracking::class, $issue->id);
        self::assertInstanceOf(IssueTracking::class, $updated);
        self::assertSame('closed', $updated->status);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testReaderCannotPreviewCreateOrRefreshIssues(): void
    {
        $provider = self::getContainer()->get('security.user.provider.concrete.administrators');
        self::assertInstanceOf(InMemoryUserProvider::class, $provider);
        $reader = new InMemoryUser('issue-reader', null, ['ROLE_USER']);
        $provider->createUser($reader);
        $this->client->loginUser($reader);
        foreach (['/findings/issues/preview', '/findings/issues', '/issues/00000000-0000-4000-8000-000000000000/refresh'] as $path) {
            $this->client->request('POST', $path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testInvalidSelectionIs422AndMissingFindingIs404(): void
    {
        $finding = $this->finding();
        $crawler = $this->client->request('GET', '/applications/'.$finding->repository->id);
        $token = $crawler->filter('form[action="/findings/issues/preview"] input[name="_token"]')->attr('value');
        foreach ([[], ['invalid']] as $ids) {
            $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => $ids]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => ['00000000-0000-4000-8000-000000000000']]);
        self::assertResponseStatusCodeSame(404);
    }

    private function finding(): Finding
    {
        $connection = new ProviderConnection();
        $connection->name = 'Issue test connection';
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = 'issue-http-test';
        $repository->name = 'team/issue-http-test';
        $finding = new Finding();
        $finding->repository = $repository;
        $finding->fingerprint = 'main:runtime';
        $finding->title = 'Runtime à migrer';
        foreach ([$connection, $repository, $finding] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $finding;
    }
}
