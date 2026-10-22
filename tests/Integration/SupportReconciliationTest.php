<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Service\FindingReconciler;
use App\Domain\Analysis\AnalysisResult;
use App\Domain\Model\Finding;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;

final class SupportReconciliationTest extends DatabaseTestCase
{
    public function testOnlyPositiveSameBranchSupportEvidenceCanResolvePartialScanFindings(): void
    {
        $connection = new ProviderConnection();
        $this->em->persist($connection);
        foreach ([
            'verified' => ['known', 100, 'main', true],
            'unknown' => ['unknown', 0, 'main', false],
            'weaker' => ['known', 60, 'main', false],
            'other-branch' => ['known', 100, 'legacy', false],
            'estimated' => ['estimated', 60, 'main', false],
            'invalid-date' => ['known', 100, 'main', false],
            'empty-source' => ['known', 100, 'main', false],
            'invalid-verification' => ['known', 100, 'main', false],
        ] as $case => [$status, $confidence, $branch, $resolved]) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = $case;
            $finding = new Finding();
            $finding->repository = $repository;
            $finding->type = 'end_of_support';
            $finding->fingerprint = 'synthetic-old-support';
            $finding->details = ['technology' => 'Symfony', 'confidence' => 100, 'branch' => 'main'];
            $scan = new Scan();
            $scan->repository = $repository;
            $scan->branch = $branch;
            foreach ([$repository, $finding, $scan] as $entity) {
                $this->em->persist($entity);
            }
            $this->em->flush();
            $result = new AnalysisResult([[
                'name' => 'Symfony', 'declared' => '5.4.*', 'resolved' => 'v5.4.49',
                'source' => 'composer.lock', 'confidence' => 100,
                'supportStatus' => $status, 'supportConfidence' => $confidence,
                'supportVerifiedAt' => 'invalid-verification' === $case ? '' : '2026-10-22T00:00:00+00:00',
                'supportSource' => 'empty-source' === $case ? '' : 'https://symfony.com/releases/5.4.json',
                'eol' => 'invalid-date' === $case ? '2029-02-30' : '2029-02-28',
            ]], [], [], null, 50, 75, ['Unrelated package registry unavailable.']);
            (new FindingReconciler($this->em))->reconcile($scan, $result, false);
            self::assertSame($resolved, null !== $finding->resolvedAt, $case);
        }
    }
}
