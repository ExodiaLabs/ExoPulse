<?php

declare(strict_types=1);

namespace App\Tests\UI;

use App\Application\Service\ConfigurationStore;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Presentation\Twig\FreshnessExtension;
use App\Tests\Integration\DatabaseTestCase;
use Twig\Environment;

final class FreshnessViewTest extends DatabaseTestCase
{
    public function testStaleScoresAreClearlyHistoricalAndPartialScoresAreProvisional(): void
    {
        $connection = new ProviderConnection();
        $connection->name = 'Synthetic connection';
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->name = 'Synthetic app';
        $repository->latestScore = 95;
        $repository->lastScanAt = new \DateTimeImmutable('-8 days');
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $extension = $twig->getExtension(FreshnessExtension::class);
        $html = $twig->render('components/score.html.twig', ['score' => $repository->latestScore, 'stale' => $extension->isStale($repository->lastScanAt)]);
        self::assertStringContainsString('À actualiser', $html);
        self::assertStringContainsString('Ancien score : 95/100', $html);
        self::assertStringNotContainsString('class="text-success">95/100', $html);
        self::assertSame(95, $repository->latestScore);
        $history = $twig->render('components/score.html.twig', ['score' => 100, 'provisional' => true]);
        self::assertStringContainsString('Provisoire : 100/100', $history);
        self::assertStringNotContainsString('class="text-success">100/100', $history);
    }

    public function testResetLoadsChangedThresholdForTheNextRequest(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $extension = $twig->getExtension(FreshnessExtension::class);
        $date = new \DateTimeImmutable('-2 days');
        self::assertFalse($extension->isStale($date));
        $store = self::getContainer()->get(ConfigurationStore::class);
        self::assertInstanceOf(ConfigurationStore::class, $store);
        $store->save(array_replace(ConfigurationStore::DEFAULTS, ['staleDays' => 1]));
        $this->em->flush();
        $extension->reset();
        self::assertTrue($extension->isStale($date));
        self::assertTrue($extension->isStale(null));
    }
}
