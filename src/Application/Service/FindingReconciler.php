<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Analysis\AnalysisResult;
use App\Domain\Model\Finding;
use App\Domain\Model\Scan;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FindingReconciler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function reconcile(Scan $scan, AnalysisResult $result, bool $complete): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $existing = $this->em->getRepository(Finding::class)->findBy(['repository' => $scan->repository]);
        $byFingerprint = [];
        foreach ($existing as $finding) {
            $byFingerprint[$finding->fingerprint] = $finding;
        }
        $observed = [];
        foreach ($result->findings as $evidence) {
            $fingerprint = hash('sha256', $scan->branch.'|'.$evidence['fingerprint']);
            $finding = $byFingerprint[$fingerprint] ?? new Finding();
            $finding->repository = $scan->repository;
            $finding->fingerprint = $fingerprint;
            $finding->type = $evidence['type'];
            $finding->severity = $evidence['severity'];
            $finding->title = $evidence['title'];
            $finding->details = $evidence + ['branch' => $scan->branch, 'scanId' => $scan->id];
            $finding->firstSeenAt ??= $now;
            $finding->lastSeenAt = $now;
            $finding->resolvedAt = null;
            $this->em->persist($finding);
            $observed[$fingerprint] = true;
        }
        if (!$complete) {
            return;
        }
        foreach ($existing as $finding) {
            if (($finding->details['branch'] ?? null) === $scan->branch && !isset($observed[$finding->fingerprint]) && null === $finding->resolvedAt) {
                $finding->resolvedAt = $now;
            }
        }
    }
}
