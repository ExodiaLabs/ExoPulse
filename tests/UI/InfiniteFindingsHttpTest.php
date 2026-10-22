<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Domain\Model\Finding;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InfiniteFindingsHttpTest extends WebTestCase
{
    public function testBatchesAreBoundedPrioritizedAndKeepSelectionAssociatedWithThePreviewForm(): void
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
            $repository->externalId = 'synthetic-infinite';
            $other = new Repository();
            $other->connection = $connection;
            $other->externalId = 'synthetic-other';
            foreach ([$connection, $repository, $other] as $entity) {
                $em->persist($entity);
            }
            for ($i = 0; $i < 45; ++$i) {
                $finding = new Finding();
                $finding->repository = 44 === $i ? $other : $repository;
                $finding->fingerprint = 'infinite-'.$i;
                $finding->title = 'Synthetic finding '.$i;
                $finding->firstSeenAt = new \DateTimeImmutable('-1 day');
                $finding->severity = 42 === $i ? 'critical' : 'warning';
                if (43 === $i) {
                    $finding->resolvedAt = new \DateTimeImmutable();
                }
                $em->persist($finding);
            }
            $em->flush();
            $url = '/applications/'.$repository->id;
            $client->request('GET', $url.'?findingsPage=2', server: ['HTTP_TURBO_FRAME' => 'findings-page-2']);
            self::assertResponseRedirects('/login');
            $client->request('GET', '/login');
            $client->submitForm('Se connecter', ['_username' => 'test-admin', '_password' => 'only-for-tests']);
            $seen = [];
            for ($page = 1; $page <= 3; ++$page) {
                $crawler = $client->request('GET', $url.'?findingsPage='.$page, server: $page > 1 ? ['HTTP_TURBO_FRAME' => 'findings-page-'.$page] : []);
                self::assertResponseIsSuccessful();
                self::assertSelectorCount(3 === $page ? 3 : 20, '.exo-finding-card');
                self::assertSelectorExists('#findings-page-'.$page);
                if (1 === $page) {
                    self::assertSelectorExists('#migration-selection');
                    self::assertSame('Synthetic finding 42', $crawler->filter('.exo-finding-card h3')->first()->text());
                    self::assertSelectorTextContains('#issue-planning', '43 constat(s)');
                } else {
                    self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
                }
                foreach ($crawler->filter('input[name="findings[]"]') as $checkbox) {
                    self::assertInstanceOf(\DOMElement::class, $checkbox);
                    self::assertSame('migration-selection', $checkbox->getAttribute('form'));
                    $seen[] = $checkbox->getAttribute('value');
                }
                if ($page < 3) {
                    self::assertSelectorExists('turbo-frame[loading="lazy"][src]');
                    self::assertSelectorExists('a[href*="findingsPage='.($page + 1).'"]');
                } else {
                    self::assertSelectorNotExists('turbo-frame[loading="lazy"]');
                    self::assertSelectorTextContains('[role="status"]', 'Fin des constats');
                }
            }
            self::assertCount(43, array_unique($seen));
            $client->request('GET', $url.'?findingsPage=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('html');
            self::assertSelectorExists('#migration-selection');
            $client->request('GET', '/applications/'.$other->id.'?findingsPage=-10');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.exo-finding-card');
            $client->request('GET', '/applications/'.$other->id.'?findingsPage=999999');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.exo-finding-card');
        } finally {
            $em->getConnection()->rollBack();
        }
    }
}
