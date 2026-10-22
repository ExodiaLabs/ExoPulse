<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @phpstan-import-type Finding from AnalysisResult */
final class HealthPolicy
{
    /** @param list<Finding> $findings
     * @param array<string, int> $weights
     */
    public function score(array $findings, int $coverage, int $confidence, array $weights = []): ?int
    {
        if ($coverage < 50 || $confidence < 100) {
            return null;
        }
        $policy = array_replace(['end_of_support' => 40, 'end_of_maintenance' => 10, 'major' => 10, 'minor' => 3, 'patch' => 1], $weights);
        $deductions = [];
        foreach ($findings as $finding) {
            if ('unknown' === $finding['type'] || $finding['confidence'] < 50) {
                continue;
            }
            $type = $finding['type'];
            $deductions[$type] = ($deductions[$type] ?? 0) + max(0, min(100, $policy[$type] ?? 0));
        }
        // Each category is capped, so a large dependency graph does not overwhelm all other factors.
        $penalty = 0;
        foreach ($deductions as $type => $deduction) {
            $penalty += min($deduction, in_array($type, ['end_of_support', 'end_of_maintenance'], true) ? 60 : 25);
        }

        return max(0, 100 - $penalty);
    }
}
