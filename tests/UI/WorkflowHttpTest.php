<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Application\Handler\AnalyzeRepositoryHandler;
use App\Application\Handler\SynchronizeConnectionHandler;
use App\Application\Message\AnalyzeRepository;
use App\Application\Message\SynchronizeConnection;
use App\Application\Service\ConfigurationStore;
use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Domain\Model\SyncJob;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Registry\CatalogHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class WorkflowHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $issueCreations = 0;
    private int $connectionTestStatus = 200;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
        self::getContainer()->set(GitEndpointPolicy::class, new GitEndpointPolicy('git.workflow.example,127.0.0.1', 'git.workflow.example,127.0.0.1'));
        self::getContainer()->set('exopulse.git_http_client', new MockHttpClient($this->gitResponse(...)));
        self::getContainer()->set(CatalogHttpClient::class, new CatalogHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{"packages":{"synthetic/workflow":{"0":{"version":"1.0.0"},"1":{"version":"2.0.0"}}}}'))));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
        self::assertResponseRedirects('/');
        $store = self::getContainer()->get(ConfigurationStore::class);
        self::assertInstanceOf(ConfigurationStore::class, $store);
        $store->save(array_replace(ConfigurationStore::DEFAULTS, ['externalEnrichment' => 1]));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testRealHttpWorkflowDiscoversAnalysesAndCreatesOneConfirmedIssue(): void
    {
        $this->client->request('GET', '/connections/new');
        self::assertSelectorTextContains('nav[aria-label="Navigation principale"] a[aria-current="page"]', 'Connexions');
        $this->client->submitForm('Enregistrer la connexion', [
            'connection[name]' => 'Synthetic workflow',
            'connection[provider]' => 'gitlab',
            'connection[baseUrl]' => 'https://git.workflow.example',
            'connection[token]' => 'synthetic-workflow-token',
            'connection[organizations]' => 'team',
            'connection[frequencyHours]' => '24',
        ]);
        self::assertResponseStatusCodeSame(303);
        $connection = $this->em->getRepository(ProviderConnection::class)->findOneBy(['name' => 'Synthetic workflow']);
        self::assertInstanceOf(ProviderConnection::class, $connection);
        // Route all synthetic API traffic to a literal IP: the SSRF guard must not require real DNS in this test.
        $connection->baseUrl = 'https://127.0.0.1';
        $this->em->flush();
        $crawler = $this->client->request('GET', '/connections/'.$connection->id);
        $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->request('GET', '/connections/'.$connection->id);
        $this->client->submit($crawler->selectButton('Synchroniser')->form());
        self::assertResponseStatusCodeSame(303);
        $job = $this->em->getRepository(SyncJob::class)->findOneBy(['connection' => $connection]);
        self::assertInstanceOf(SyncJob::class, $job);
        $sync = self::getContainer()->get(SynchronizeConnectionHandler::class);
        self::assertInstanceOf(SynchronizeConnectionHandler::class, $sync);
        $sync(new SynchronizeConnection($job->id));
        $repository = $this->em->getRepository(Repository::class)->findOneBy(['connection' => $connection]);
        self::assertInstanceOf(Repository::class, $repository);
        self::assertSame('team/workflow', $repository->name);
        $scan = $this->em->getRepository(Scan::class)->findOneBy(['repository' => $repository]);
        self::assertInstanceOf(Scan::class, $scan);
        $analyse = self::getContainer()->get(AnalyzeRepositoryHandler::class);
        self::assertInstanceOf(AnalyzeRepositoryHandler::class, $analyse);
        $analyse(new AnalyzeRepository($scan->id));
        self::assertSame('completed', $scan->status);
        self::assertNotNull($scan->score);
        $finding = $this->em->getRepository(Finding::class)->findOneBy(['repository' => $repository]);
        self::assertInstanceOf(Finding::class, $finding);
        $crawler = $this->client->request('GET', '/applications/'.$repository->id);
        $this->client->submit($crawler->selectButton('Lancer une analyse')->form());
        self::assertResponseStatusCodeSame(303);
        $manual = $this->em->getRepository(Scan::class)->findOneBy(['repository' => $repository, 'status' => 'queued']);
        self::assertInstanceOf(Scan::class, $manual);
        $analyse(new AnalyzeRepository($manual->id));
        self::assertSame('completed', $manual->status);
        foreach (['/', '/applications', '/applications/'.$repository->id, '/applications/'.$repository->id.'/dependencies', '/applications/'.$repository->id.'/history', '/history', '/findings'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/applications/'.$repository->id.'/dependencies');
        self::assertSelectorTextContains('nav[aria-label="Navigation principale"] a[aria-current="page"]', 'Applications');
        self::assertSelectorTextContains('main', 'synthetic/workflow');
        self::assertSelectorTextContains('main', '1.0.0');
        self::assertSelectorTextContains('main', '2.0.0');
        foreach (['/applications/'.$repository->id.'/scan', '/findings/issues/preview', '/findings/issues'] as $path) {
            $this->client->request('POST', $path);
            self::assertResponseStatusCodeSame(403);
        }
        $crawler = $this->client->request('GET', '/applications/'.$repository->id);
        $token = $crawler->filter('form[action="/findings/issues/preview"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => [$finding->id]]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->issueCreations);
        $this->client->submitForm('Confirmer la création manuelle');
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->issueCreations);
        $issue = $this->em->getRepository(IssueTracking::class)->findOneBy(['repository' => $repository]);
        self::assertInstanceOf(IssueTracking::class, $issue);
        self::assertSame('open', $issue->status);
        $crawler = $this->client->request('GET', '/applications/'.$repository->id);
        $token = $crawler->filter('form[action="/findings/issues/preview"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/findings/issues/preview', ['_token' => $token, 'findings' => [$finding->id]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/findings/issues"]');
        self::assertSame(1, $this->issueCreations);
        $this->connectionTestStatus = 401;
        $crawler = $this->client->request('GET', '/connections/'.$connection->id);
        $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/connections/'.$connection->id);
        self::assertSelectorTextContains('main', 'Accès Git refusé');
        self::assertStringNotContainsString('synthetic-upstream-private-body', (string) $this->client->getResponse()->getContent());
        $provider = self::getContainer()->get('security.user.provider.concrete.administrators');
        self::assertInstanceOf(InMemoryUserProvider::class, $provider);
        $reader = new InMemoryUser('workflow-reader', null, ['ROLE_USER']);
        $provider->createUser($reader);
        $this->client->loginUser($reader);
        $this->client->request('GET', '/applications/'.$repository->id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('nav[aria-label="Navigation principale"] a[href="/connections"]');
        self::assertSelectorNotExists('nav[aria-label="Navigation principale"] a[href="/settings"]');
        $this->client->request('POST', '/findings/issues/preview');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/connections');
        self::assertResponseStatusCodeSame(403);
    }

    private function gitResponse(string $method, string $url): MockResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ('/api/v4/user' === $path) {
            return new MockResponse(200 === $this->connectionTestStatus ? '{"id":1}' : 'synthetic-upstream-private-body', ['http_code' => $this->connectionTestStatus]);
        }
        if ('/api/v4/groups/team/projects' === $path) {
            return new MockResponse('[{"id":42,"path_with_namespace":"team/workflow","web_url":"https://127.0.0.1/team/workflow","default_branch":"main","archived":false}]');
        }
        if ('/api/v4/projects/42/repository/commits/main' === $path) {
            return new MockResponse('{"id":"synthetic-workflow-commit"}');
        }
        if (is_string($path) && str_contains($path, '/repository/files/')) {
            $name = rawurldecode(substr($path, strrpos($path, '/') + 1));
            $files = ['composer.json' => '{"require":{"synthetic/workflow":"^1.0"}}', 'composer.lock' => '{"packages":[{"name":"synthetic/workflow","version":"1.0.0"}]}'];
            if (isset($files[$name])) {
                return new MockResponse(json_encode(['encoding' => 'base64', 'content' => base64_encode($files[$name])], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('{}', ['http_code' => 404]);
        }
        if ('POST' === $method && '/api/v4/projects/42/issues' === $path) {
            ++$this->issueCreations;

            return new MockResponse('{"iid":12,"web_url":"https://127.0.0.1/team/workflow/-/issues/12","state":"opened"}');
        }
        throw new \LogicException('Unexpected synthetic Git request: '.$method.' '.$url);
    }
}
