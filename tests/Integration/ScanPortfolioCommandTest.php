<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanPortfolioCommandTest extends DatabaseTestCase
{
    public function testDryRunDoesNotWriteAndRepeatedRequestsReusePendingScans(): void
    {
        $connection = new ProviderConnection();
        $connection->branch = 'main,legacy';
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = 'synthetic-bulk-scan';
        $repository->name = 'synthetic/application';
        $disabled = new ProviderConnection();
        $disabled->active = false;
        $excluded = new Repository();
        $excluded->connection = $disabled;
        $excluded->externalId = 'synthetic-disabled-scan';
        foreach ([$connection, $repository, $disabled, $excluded] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:scan:portfolio'));
        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertSame(0, $this->em->getRepository(Scan::class)->count());
        self::assertStringContainsString('demandes par branche : 2', $tester->getDisplay());
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $this->em->getRepository(Scan::class)->count(['repository' => $repository]));
        self::assertSame(0, $this->em->getRepository(Scan::class)->count(['repository' => $excluded]));
    }
}
