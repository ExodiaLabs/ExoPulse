<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Security\SecretCipher;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class ConnectionHttpTest extends WebTestCase
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

    public function testConnectionCreationEncryptsTokenAndEditNeverDisplaysIt(): void
    {
        $this->client->request('GET', '/connections/new');
        self::assertSelectorExists('form[data-controller="wizard"]');
        self::assertSelectorCount(4, 'form[data-controller="wizard"] fieldset[data-wizard-target="step"]');
        self::assertSelectorNotExists('form[data-controller="wizard"] fieldset[hidden], form[data-controller="wizard"] fieldset[aria-hidden="true"], form[data-controller="wizard"] fieldset.hidden');
        self::assertSelectorExists('form[data-controller="wizard"] button[type="submit"]:not([hidden])');
        $this->client->submitForm('Enregistrer la connexion', ['connection[name]' => 'Synthetic connection', 'connection[provider]' => 'github', 'connection[baseUrl]' => 'https://github.com', 'connection[token]' => 'synthetic-private-token', 'connection[organizations]' => 'team-one, team-two', 'connection[frequencyHours]' => '24']);
        self::assertResponseStatusCodeSame(303);
        $connection = $this->em->getRepository(ProviderConnection::class)->findOneBy(['name' => 'Synthetic connection']);
        self::assertInstanceOf(ProviderConnection::class, $connection);
        self::assertSame(['team-one', 'team-two'], $connection->organizations);
        self::assertNotSame('synthetic-private-token', $connection->encryptedToken);
        $cipher = self::getContainer()->get(SecretCipher::class);
        self::assertInstanceOf(SecretCipher::class, $cipher);
        self::assertSame('synthetic-private-token', $cipher->decrypt($connection->encryptedToken));
        $original = $connection->encryptedToken;
        $this->client->request('GET', '/connections/'.$connection->id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-controller~="wizard"]');
        self::assertSelectorNotExists('nav[aria-label="Étapes de création"]');
        self::assertStringNotContainsString('synthetic-private-token', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($original, (string) $this->client->getResponse()->getContent());
        $this->client->submitForm('Enregistrer la connexion', ['connection[name]' => 'Renamed synthetic connection', 'connection[token]' => '']);
        self::assertResponseStatusCodeSame(303);
        $updated = $this->em->find(ProviderConnection::class, $connection->id);
        self::assertInstanceOf(ProviderConnection::class, $updated);
        self::assertSame('Renamed synthetic connection', $updated->name);
        self::assertSame($original, $updated->encryptedToken);
        foreach (['test', 'sync', 'disable'] as $action) {
            $this->client->request('POST', '/connections/'.$connection->id.'/'.$action);
            self::assertResponseStatusCodeSame(403);
        }
        $crawler = $this->client->request('GET', '/connections/'.$connection->id);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Désactiver')->form());
        self::assertResponseStatusCodeSame(303);
        $persisted = $this->em->find(ProviderConnection::class, $connection->id);
        self::assertInstanceOf(ProviderConnection::class, $persisted);
        self::assertFalse($persisted->active);
    }

    public function testInvalidWizardSchedulePreservesFieldsWithoutRedisplayingToken(): void
    {
        $before = $this->em->getRepository(ProviderConnection::class)->count();
        $this->client->request('GET', '/connections/new');
        $crawler = $this->client->submitForm('Enregistrer la connexion', [
            'connection[name]' => 'Synthetic wizard correction',
            'connection[provider]' => 'gitlab',
            'connection[baseUrl]' => 'https://gitlab.com',
            'connection[token]' => 'synthetic-wizard-private-token',
            'connection[organizations]' => 'synthetic-team',
            'connection[includedRepositories]' => 'synthetic-team/api-*',
            'connection[branch]' => 'release/legacy',
            'connection[frequencyHours]' => '0',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->em->getRepository(ProviderConnection::class)->count());
        self::assertSelectorExists('form[data-controller="wizard"]');
        self::assertSelectorCount(4, 'fieldset[data-wizard-target="step"]');
        self::assertSelectorCount(1, 'fieldset[data-step-invalid="true"]');
        self::assertSelectorExists('fieldset[data-step-invalid="true"] input[name="connection[frequencyHours]"]');
        self::assertSelectorNotExists('fieldset[hidden], fieldset[aria-hidden="true"], fieldset.hidden');
        foreach ([
            'name' => 'Synthetic wizard correction',
            'baseUrl' => 'https://gitlab.com',
            'organizations' => 'synthetic-team',
            'includedRepositories' => 'synthetic-team/api-*',
            'branch' => 'release/legacy',
            'frequencyHours' => '0',
        ] as $field => $value) {
            self::assertSame($value, $crawler->filter('input[name="connection['.$field.']"]')->attr('value'));
        }
        self::assertSame('gitlab', $crawler->filter('select[name="connection[provider]"] option[selected]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name="connection[token]"]')->attr('value', ''));
        self::assertStringNotContainsString('synthetic-wizard-private-token', (string) $this->client->getResponse()->getContent());
    }

    public function testUnsafeInstanceIsRejectedBeforePersistence(): void
    {
        $before = $this->em->getRepository(ProviderConnection::class)->count();
        $this->client->request('GET', '/connections/new');
        $this->client->submitForm('Enregistrer la connexion', ['connection[name]' => 'Unsafe synthetic connection', 'connection[provider]' => 'gitlab', 'connection[baseUrl]' => 'https://127.0.0.1', 'connection[token]' => 'synthetic-token', 'connection[frequencyHours]' => '24']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->em->getRepository(ProviderConnection::class)->count());
    }

    public function testReaderCannotAccessConnectionManagement(): void
    {
        $provider = self::getContainer()->get('security.user.provider.concrete.administrators');
        self::assertInstanceOf(InMemoryUserProvider::class, $provider);
        $reader = new InMemoryUser('synthetic-reader', null, ['ROLE_USER']);
        $provider->createUser($reader);
        $this->client->loginUser($reader);
        $this->client->request('GET', '/connections');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/connections/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testChangingOrganizationsSuspendsImportedRepositoriesUntilDiscovery(): void
    {
        $connection = $this->scopeFixture();
        $this->client->request('GET', '/connections/'.$connection->id.'/edit');
        $this->client->submitForm('Enregistrer la connexion', ['connection[organizations]' => 'new-owner']);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $stored = $this->em->find(ProviderConnection::class, $connection->id);
        self::assertInstanceOf(ProviderConnection::class, $stored);
        self::assertNull($stored->nextSyncAt);
        $repositories = $this->em->getRepository(Repository::class)->findBy(['connection' => $stored]);
        self::assertCount(2, $repositories);
        foreach ($repositories as $repository) {
            self::assertFalse($repository->monitored);
        }
    }

    public function testChangedPatternsImmediatelyRemoveExcludedRepositories(): void
    {
        $connection = $this->scopeFixture();
        $this->client->request('GET', '/connections/'.$connection->id.'/edit');
        $this->client->submitForm('Enregistrer la connexion', ['connection[excludedRepositories]' => 'old-owner/private']);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $repositories = $this->em->getRepository(Repository::class)->findBy(['connection' => $connection]);
        foreach ($repositories as $repository) {
            self::assertSame('old-owner/public' === $repository->name, $repository->monitored);
        }
        $stored = $this->em->find(ProviderConnection::class, $connection->id);
        self::assertInstanceOf(ProviderConnection::class, $stored);
        self::assertNull($stored->nextSyncAt);
    }

    public function testProviderAndInstanceIdentityCannotBeChanged(): void
    {
        $connection = $this->scopeFixture();
        foreach (['connection[provider]' => 'gitlab', 'connection[baseUrl]' => 'https://gitlab.com'] as $field => $value) {
            $this->client->request('GET', '/connections/'.$connection->id.'/edit');
            $this->client->submitForm('Enregistrer la connexion', [$field => $value]);
            self::assertResponseStatusCodeSame(422);
            $this->em->clear();
            $stored = $this->em->find(ProviderConnection::class, $connection->id);
            self::assertInstanceOf(ProviderConnection::class, $stored);
            self::assertSame('github', $stored->provider);
            self::assertSame('https://github.com', $stored->baseUrl);
        }
    }

    public function testEditDuringSynchronizationIsRejectedWithoutChangingData(): void
    {
        $connection = $this->scopeFixture();
        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $lock = (new LockFactory(new DoctrineDbalPostgreSqlStore($other)))->createLock('sync-'.$connection->id);
        self::assertTrue($lock->acquire());
        try {
            $this->client->request('GET', '/connections/'.$connection->id.'/edit');
            self::assertResponseIsSuccessful();
            $this->client->submitForm('Enregistrer la connexion', ['connection[name]' => 'Must not be saved', 'connection[organizations]' => 'removed-owner']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Synchronisation en cours');
            $this->em->clear();
            $stored = $this->em->find(ProviderConnection::class, $connection->id);
            self::assertInstanceOf(ProviderConnection::class, $stored);
            self::assertSame('Scoped synthetic connection', $stored->name);
            self::assertSame(['old-owner'], $stored->organizations);
            foreach ($this->em->getRepository(Repository::class)->findBy(['connection' => $stored]) as $repository) {
                self::assertTrue($repository->monitored);
            }
        } finally {
            $lock->release();
            $other->close();
        }
    }

    private function scopeFixture(): ProviderConnection
    {
        $connection = new ProviderConnection();
        $connection->name = 'Scoped synthetic connection';
        $connection->organizations = ['old-owner'];
        $connection->nextSyncAt = new \DateTimeImmutable('+1 day');
        $cipher = self::getContainer()->get(SecretCipher::class);
        self::assertInstanceOf(SecretCipher::class, $cipher);
        $connection->encryptedToken = $cipher->encrypt('synthetic-scope-token');
        $this->em->persist($connection);
        foreach (['public', 'private'] as $name) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->name = 'old-owner/'.$name;
            $repository->externalId = $name;
            $this->em->persist($repository);
        }
        $this->em->flush();

        return $connection;
    }
}
