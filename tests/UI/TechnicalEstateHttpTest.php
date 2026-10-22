<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Domain\Model\Finding;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TechnicalEstateHttpTest extends WebTestCase
{
    public function testLegacyRemainsVisibleAndFilterableWhenGlobalScoreIsUnknown(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->beginTransaction();
        try {
            $connection = new ProviderConnection();
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = 'synthetic-legacy-estate';
            $repository->name = 'synthetic/legacy-symfony-four';
            $scan = new Scan();
            $scan->repository = $repository;
            $scan->branch = 'main';
            $scan->status = 'partial';
            $scan->results = ['technologies' => [['name' => 'Symfony', 'declared' => '4.4.*', 'resolved' => 'v4.4.51', 'source' => 'composer.lock', 'confidence' => 100]], 'errors' => ['Synthetic unrelated registry unavailable.']];
            $repository->latestScan = $scan;
            $repository->lastScanAt = new \DateTimeImmutable();
            $finding = new Finding();
            $finding->repository = $repository;
            $finding->type = 'end_of_support';
            $finding->severity = 'critical';
            $finding->title = 'Symfony hors support';
            $finding->fingerprint = 'synthetic-legacy-estate';
            $finding->firstSeenAt = new \DateTimeImmutable('-60 days');
            $finding->details = ['technology' => 'Symfony', 'current' => 'v4.4.51', 'confidence' => 100, 'source' => 'https://symfony.com/releases/4.4.json', 'verifiedAt' => '2026-10-22T00:00:00+00:00', 'eol' => '2023-11-30'];
            foreach ([$connection, $repository, $scan, $finding] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $client->request('GET', '/login');
            $client->submitForm('Se connecter', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
            $client->request('GET', '/applications?framework=Symfony&version=4.4&risk=critical&legacy=confirmed&sort=urgency', server: ['HTTP_TURBO_FRAME' => 'applications-results']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#applications-results', $repository->name);
            self::assertSelectorTextContains('#applications-results', 'Legacy confirmé');
            self::assertSelectorTextContains('#applications-results', 'Critique');
            self::assertSelectorExists('select[name="framework"] option[value="Symfony"][selected]');
            $client->request('GET', '/applications?framework=Symfony&version=6.4');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('#applications-results', $repository->name);
            $client->request('GET', '/applications/'.$repository->id);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Legacy confirmé');
            self::assertSelectorTextContains('main', 'Indépendant du score');
            self::assertSelectorTextContains('main', '2023-11-30');
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section[aria-label="Indicateurs du parc"]', 'Legacy confirmé');
            self::assertSelectorTextContains('main', 'Technologies du parc');
        } finally {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
        }
    }
}
