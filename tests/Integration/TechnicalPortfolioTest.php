<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Service\ConfigurationStore;
use App\Domain\Model\Finding;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use App\Infrastructure\Persistence\TechnicalPortfolio;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

final class TechnicalPortfolioTest extends DatabaseTestCase
{
    public function testPartialEolEvidencePrioritizesFrameworkWithoutInventingScore(): void
    {
        $framework = $this->repository('Framework legacy', [$this->technology('Symfony', '4.4.50')]);
        $runtime = $this->repository('Runtime estimated', [$this->technology('PHP', '7.4.33', 70)]);
        $unknown = $this->repository('Partial without evidence', [$this->technology('PHP', '8.4.0')]);
        $this->finding($framework, 'end_of_support', 'Symfony', 100);
        $this->finding($framework, 'end_of_support', 'Symfony', 100);
        $this->finding($runtime, 'end_of_support', 'PHP', 70);
        $this->em->flush();
        $portfolio = new TechnicalPortfolio($this->em);
        $data = $portfolio->repositories(['sort' => 'criticality']);
        self::assertSame($framework->id, $data['repositories'][0]->id);
        self::assertNull($data['repositories'][0]->latestScore);
        self::assertSame('critical', $data['diagnostics'][$framework->id]['risk']);
        self::assertSame('confirmed', $data['diagnostics'][$framework->id]['legacy']);
        self::assertTrue($data['diagnostics'][$framework->id]['partial']);
        self::assertSame('high', $data['diagnostics'][$runtime->id]['risk']);
        self::assertSame('suspected', $data['diagnostics'][$runtime->id]['legacy']);
        self::assertSame('unknown', $data['diagnostics'][$unknown->id]['risk']);
        self::assertSame('unknown', $data['diagnostics'][$unknown->id]['legacy']);
        $stats = $portfolio->stats();
        self::assertSame(3, $stats['total']);
        self::assertSame(2, $stats['unsupported']);
        self::assertSame(1, $stats['legacyConfirmed']);
        self::assertSame(1, $stats['legacySuspected']);
        self::assertSame(1, $stats['unknown']);
        self::assertGreaterThan(0, $data['diagnostics'][$framework->id]['eolDurationDays']);
    }

    public function testInvalidExtremeDatesCannotMaskValidVerificationAndEolDates(): void
    {
        $repository = $this->repository('Mixed dates', [
            $this->technology('Symfony', '4.4.50') + ['supportVerifiedAt' => '9999-99-99T99:00:00Z'],
            $this->technology('PHP', '8.4.0') + ['supportVerifiedAt' => '2026-10-21T12:00:00+00:00'],
        ]);
        $valid = $this->finding($repository, 'end_of_support', 'Symfony', 100);
        $valid->details = array_replace($valid->details, ['eol' => '2024-01-01', 'verifiedAt' => '2026-10-20T12:00:00+00:00']);
        foreach (['0000-00-00', '9999-99-99'] as $invalid) {
            $finding = $this->finding($repository, 'end_of_support', 'Symfony', 100);
            $finding->details = array_replace($finding->details, ['eol' => $invalid, 'verifiedAt' => $invalid.'T99:00:00Z']);
        }
        $this->em->flush();
        $diagnostic = (new TechnicalPortfolio($this->em))->diagnosticsFor([$repository->id])[$repository->id];
        self::assertSame('2026-10-21T12:00:00+00:00', $diagnostic['lastVerifiedAt']);
        self::assertSame((int) (new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('%a'), $diagnostic['eolDurationDays']);
        self::assertSame('critical', $diagnostic['risk']);
    }

    public function testVersionMatchesOneTechnologyObservationAndRespectsNumericBoundaries(): void
    {
        $wrongPair = $this->repository('Wrong pair', [$this->technology('PHP', '4.4.0'), $this->technology('Symfony', '8.0.0')]);
        $correct = $this->repository('Correct pair', [$this->technology('PHP', '8.4.0'), $this->technology('Symfony', '4.4.50')]);
        $this->repository('Wrong numeric boundary', [$this->technology('Symfony', '14.4.0')]);
        $declared = $this->repository('Legacy constraint', [['name' => 'Symfony', 'declared' => '^4.4', 'resolved' => null, 'confidence' => 50, 'source' => 'composer.json']]);
        $this->em->flush();
        $portfolio = new TechnicalPortfolio($this->em);
        $data = $portfolio->repositories(['technology' => 'Symfony', 'version' => '4.4']);
        self::assertSame(2, $data['total']);
        $ids = array_map(static fn (Repository $repository): string => $repository->id, $data['repositories']);
        self::assertContains($correct->id, $ids);
        self::assertContains($declared->id, $ids);
        self::assertNotContains($wrongPair->id, $ids);
        self::assertSame(2, $portfolio->repositories(['language' => 'PHP', 'framework' => 'Symfony', 'version' => '4.4'])['total']);
        self::assertSame(0, $portfolio->repositories(['technology' => 'Symfony', 'version' => "4.4' OR 1=1 --"])['total']);
        self::assertSame(0, $portfolio->repositories(['connection' => "' OR 1=1 --"])['total']);
    }

    public function testFacetsAndInventoryUseLatestObservationsAndCountRepositoriesOnce(): void
    {
        $repository = $this->repository('Latest inventory', [$this->technology('PHP', '7.4.33'), $this->technology('PHP', '7.4.30'), $this->technology('Symfony HttpKernel', '4.4.50'), $this->technology('TypeScript', '5.0.0')]);
        $old = new Scan();
        $old->repository = $repository;
        $old->results = ['technologies' => [$this->technology('React', '16.0.0')]];
        $this->em->persist($old);
        $this->finding($repository, 'end_of_support', 'PHP', 70);
        $this->finding($repository, 'end_of_support', 'PHP', 70);
        $resolved = $this->finding($repository, 'major', 'PHP', 100);
        $resolved->resolvedAt = new \DateTimeImmutable();
        $this->em->flush();
        $portfolio = new TechnicalPortfolio($this->em);
        self::assertSame(['technologies' => ['PHP', 'Symfony HttpKernel', 'TypeScript'], 'languages' => ['PHP', 'TypeScript'], 'frameworks' => ['Symfony']], $portfolio->facets());
        $inventory = $portfolio->technologyInventory();
        self::assertSame(['name' => 'PHP', 'projects' => 1, 'eolProjects' => 1], $inventory[0]);
        self::assertSame(0, $portfolio->stats()['outdated']);
        self::assertSame(1, $portfolio->repositories(['framework' => 'Symfony'])['total']);
    }

    public function testMaintenanceMajorMinorAndCompleteCoverageStayDistinct(): void
    {
        $maintenance = $this->repository('Maintenance', [$this->technology('PHP', '8.2.0')]);
        $major = $this->repository('Major', [$this->technology('Vue', '2.7.0')]);
        $minor = $this->repository('Minor', [$this->technology('React', '18.2.0')]);
        $healthy = $this->repository('Verified', [$this->technology('PHP', '8.4.0') + ['supportVerifiedAt' => '2026-10-21T12:00:00+00:00']], true);
        $this->finding($maintenance, 'end_of_maintenance', 'PHP', 70);
        $this->finding($major, 'major', 'Vue', 100);
        $this->finding($minor, 'minor', 'React', 100);
        $this->em->flush();
        $portfolio = new TechnicalPortfolio($this->em);
        $diagnostics = $portfolio->diagnosticsFor([$maintenance->id, $major->id, $minor->id, $healthy->id, 'invalid']);
        self::assertSame('moderate', $diagnostics[$maintenance->id]['risk']);
        self::assertSame('high', $diagnostics[$major->id]['risk']);
        self::assertSame('unknown', $diagnostics[$minor->id]['risk']);
        self::assertSame('unknown', $diagnostics[$minor->id]['legacy']);
        self::assertSame('low', $diagnostics[$healthy->id]['risk']);
        self::assertSame('none', $diagnostics[$healthy->id]['legacy']);
        self::assertFalse($diagnostics[$healthy->id]['partial']);
        self::assertSame('2026-10-21T12:00:00+00:00', $diagnostics[$healthy->id]['lastVerifiedAt']);
        self::assertSame(1, $portfolio->stats()['aging']);
        self::assertSame(2, $portfolio->stats()['outdated']);
    }

    public function testConfidenceThresholdsAndMalformedMetadataRemainConservative(): void
    {
        $high = $this->repository('Confidence 79', [$this->technology('Symfony', '4.4.50')]);
        $critical = $this->repository('Confidence 80', [$this->technology('Symfony', '4.4.50')]);
        $insufficient = $this->repository('Unknown confidence', []);
        $this->finding($high, 'end_of_support', 'Symfony', 79);
        $this->finding($critical, 'end_of_support', 'Symfony', 80);
        $finding = $this->finding($insufficient, 'end_of_support', 'Symfony', 0);
        $finding->details = ['technology' => 'Symfony', 'confidence' => 'not-a-number', 'eol' => '2026-99-99', 'verifiedAt' => 'invalid-date'];
        $invalidConfidence = $this->finding($insufficient, 'end_of_support', 'Symfony', 999);
        $invalidConfidence->details['confidence'] = 999;
        self::assertNotNull($insufficient->latestScan);
        $insufficient->latestScan->results = ['technologies' => 'invalid-shape'];
        $this->em->flush();
        $diagnostics = (new TechnicalPortfolio($this->em))->diagnosticsFor([$high->id, $critical->id, $insufficient->id]);
        self::assertSame('high', $diagnostics[$high->id]['risk']);
        self::assertSame('critical', $diagnostics[$critical->id]['risk']);
        self::assertSame('suspected', $diagnostics[$critical->id]['legacy']);
        self::assertSame('unknown', $diagnostics[$insufficient->id]['risk']);
        self::assertNull($diagnostics[$insufficient->id]['eolDurationDays']);
        self::assertNull($diagnostics[$insufficient->id]['lastVerifiedAt']);
        self::assertSame([], $diagnostics[$insufficient->id]['technologies']);
    }

    public function testFilteringPrecedesPaginationAcrossFiveHundredRepositories(): void
    {
        for ($index = 0; $index < 500; ++$index) {
            $repository = $this->repository(sprintf('Application %03d', $index), [$this->technology('PHP', '8.4.0')]);
            $repository->latestScore = 95;
            $repository->lastScanAt = new \DateTimeImmutable();
            if ($index >= 470) {
                $this->finding($repository, 'end_of_support', 'Symfony', 100);
            }
        }
        $this->em->flush();
        $this->em->clear();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $portfolio = new TechnicalPortfolio($this->em);
        $data = $portfolio->repositories(['risk' => 'critical', 'sort' => 'untrusted SQL'], 500);
        foreach ($data['repositories'] as $repository) {
            self::assertSame('Synthetic technical portfolio', $repository->connection->name);
            self::assertNotNull($repository->latestScan);
            self::assertSame('partial', $repository->latestScan->status);
        }
        $queries = $holder->getData()['default'] ?? [];
        self::assertIsArray($queries);
        self::assertCount(3, $queries, 'One count, one bounded SQL page and one ORM fetch-join; no relation N+1.');
        self::assertSame(30, $data['total']);
        self::assertSame(2, $data['page']);
        self::assertSame(2, $data['pages']);
        self::assertCount(5, $data['repositories']);
        self::assertSame('Application 495', $data['repositories'][0]->name);
        self::assertCount(5, $data['diagnostics']);
        self::assertSame(500, $portfolio->stats()['total']);
        self::assertSame(0, $portfolio->repositories(['q' => "' OR 1=1 --"])['total']);
        self::assertSame(500, $portfolio->repositories(['status' => 'healthy'])['total']);
    }

    public function testUnknownSnapshotsAndConfigurableFreshnessDoNotAcquireArtificialRisk(): void
    {
        $repository = $this->repository('Stale', [$this->technology('PHP', '8.4.0')], true);
        $repository->latestScore = 90;
        $repository->lastScanAt = new \DateTimeImmutable('-2 days');
        $unscanned = new Repository();
        $unscanned->connection = $repository->connection;
        $unscanned->externalId = 'unscanned';
        $unscanned->name = 'Unscanned';
        $this->em->persist($unscanned);
        $this->em->flush();
        $configuration = new ConfigurationStore($this->em);
        $configuration->save(array_replace(ConfigurationStore::DEFAULTS, ['staleDays' => 1]));
        $this->em->flush();
        $portfolio = new TechnicalPortfolio($this->em, $configuration);
        self::assertSame(0, $portfolio->repositories(['status' => 'healthy'])['total']);
        self::assertSame(2, $portfolio->repositories(['status' => 'stale'])['total']);
        self::assertSame('unknown', $portfolio->diagnosticsFor([$unscanned->id])[$unscanned->id]['risk']);
        self::assertSame(90, $repository->latestScore);
    }

    /** @param list<array<string,mixed>> $technologies */
    private function repository(string $name, array $technologies, bool $complete = false): Repository
    {
        $connection = new ProviderConnection();
        $connection->name = 'Synthetic technical portfolio';
        $repository = new Repository();
        $repository->connection = $connection;
        $repository->externalId = $name;
        $repository->name = $name;
        $scan = new Scan();
        $scan->repository = $repository;
        $scan->status = $complete ? 'completed' : 'partial';
        $scan->coverage = $complete ? 100 : 50;
        $scan->confidence = $complete ? 100 : 70;
        $scan->results = ['technologies' => $technologies, 'errors' => []];
        $repository->latestScan = $scan;
        foreach ([$connection, $repository, $scan] as $entity) {
            $this->em->persist($entity);
        }

        return $repository;
    }

    /** @return array<string,mixed> */
    private function technology(string $name, string $version, int $confidence = 100): array
    {
        return ['name' => $name, 'declared' => '^'.$version, 'resolved' => $version, 'confidence' => $confidence, 'source' => 'synthetic.lock'];
    }

    private function finding(Repository $repository, string $type, string $technology, int $confidence): Finding
    {
        $finding = new Finding();
        $finding->repository = $repository;
        $finding->fingerprint = bin2hex(random_bytes(16));
        $finding->type = $type;
        $finding->firstSeenAt = new \DateTimeImmutable('-30 days');
        $finding->details = ['technology' => $technology, 'confidence' => $confidence, 'eol' => '2024-01-01', 'verifiedAt' => '2026-10-20T12:00:00+00:00', 'source' => 'https://example.org/lifecycle'];
        $this->em->persist($finding);

        return $finding;
    }
}
