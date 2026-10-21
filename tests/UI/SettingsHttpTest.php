<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Application\Service\ConfigurationStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class SettingsHttpTest extends WebTestCase
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

    public function testSettingsAreValidatedSavedAndProtectedByCsrf(): void
    {
        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer les paramètres', ['settings[frequencyHours]' => '12', 'settings[end_of_support]' => '45', 'settings[externalEnrichment]' => '1']);
        self::assertResponseStatusCodeSame(303);
        $store = self::getContainer()->get(ConfigurationStore::class);
        self::assertInstanceOf(ConfigurationStore::class, $store);
        self::assertSame(12, $store->values()['frequencyHours']);
        self::assertSame(45, $store->values()['end_of_support']);
        self::assertSame(1, $store->values()['externalEnrichment']);
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Enregistrer les paramètres', ['settings[frequencyHours]' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(12, $store->values()['frequencyHours']);
        $this->client->request('POST', '/settings', ['settings' => array_replace(ConfigurationStore::DEFAULTS, ['frequencyHours' => 20])]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(12, $store->values()['frequencyHours']);
    }

    public function testReaderCannotChangeSettings(): void
    {
        $provider = self::getContainer()->get('security.user.provider.concrete.administrators');
        self::assertInstanceOf(InMemoryUserProvider::class, $provider);
        $reader = new InMemoryUser('settings-reader', null, ['ROLE_USER']);
        $provider->createUser($reader);
        $this->client->loginUser($reader);
        $this->client->request('GET', '/settings');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/settings');
        self::assertResponseStatusCodeSame(403);
    }
}
