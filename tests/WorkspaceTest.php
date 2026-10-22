<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WorkspaceTest extends WebTestCase
{
    public function testAnonymousCannotReadBusinessPages(): void
    {
        $client = self::createClient();
        foreach (['/', '/applications', '/connections', '/findings', '/history', '/settings'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/login');
        }
    }

    public function testAuthenticatedUserSeesExplicitlyUnavailableData(): void
    {
        $client = self::createClient();
        $this->authenticate($client);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune analyse disponible');
        self::assertSelectorCount(5, '[aria-label="Non disponible"]');
    }

    public function testLoginRejectsMissingCsrfAndDoesNotRevealCredentials(): void
    {
        $client = self::createClient();
        $client->request('POST', '/login', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertStringNotContainsString('only-for-tests', (string) $client->getResponse()->getContent());
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testLogoutCannotBeTriggeredWithGetOrMissingCsrf(): void
    {
        $client = self::createClient();
        $this->authenticate($client);
        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Se déconnecter')->form());
        self::assertResponseRedirects('/login');
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    private function authenticate(KernelBrowser $client): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
        self::assertResponseRedirects('/');
    }
}
