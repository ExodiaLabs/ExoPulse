<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Analysis\AnalysisResult;
use App\Domain\Analysis\VersionComparison;
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
        foreach ($existing as $finding) {
            if (($finding->details['branch'] ?? null) === $scan->branch && !isset($observed[$finding->fingerprint]) && null === $finding->resolvedAt && ($complete || $this->supportVerified($finding, $result))) {
                $finding->resolvedAt = $now;
            }
        }
    }

    private function supportVerified(Finding $finding, AnalysisResult $result): bool
    {
        if (!in_array($finding->type, ['end_of_support', 'end_of_maintenance', 'unknown'], true)) {
            return false;
        }
        $name = $finding->details['technology'] ?? null;
        $confidence = $finding->details['confidence'] ?? null;
        if (!is_string($name) || !is_int($confidence)) {
            return false;
        }
        $matched = false;
        foreach ($result->technologies as $technology) {
            if ($technology['name'] !== $name || 'language' === ($technology['kind'] ?? '')) {
                continue;
            }
            $matched = true;
            // Positive, equally strong evidence for every observation of this technology
            // can supersede an old support finding despite an unrelated registry outage.
            if ('known' !== ($technology['supportStatus'] ?? null)
                || ($technology['supportConfidence'] ?? 0) < max(100, $confidence)
                || null === $technology['resolved']
                || !VersionComparison::stable($technology['resolved'])
                || !is_string($technology['supportSource'] ?? null)
                || '' === trim($technology['supportSource'])
                || !$this->validDate($technology['supportVerifiedAt'] ?? null, DATE_ATOM)
                || !$this->validDate($technology['eol'] ?? null, 'Y-m-d')) {
                return false;
            }
        }

        return $matched;
    }

    private function validDate(?string $value, string $format): bool
    {
        if (null === $value || '' === $value) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat($format, $value);

        return false !== $date && $date->format($format) === $value;
    }
}
