<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/**
 * @phpstan-type Technology array{name: string, declared: ?string, resolved: ?string, confidence: int, source: string, eol?: ?string, endOfMaintenance?: ?string, supportSource?: ?string, supportVerifiedAt?: ?string}
 * @phpstan-type Dependency array{ecosystem: string, name: string, declared: ?string, resolved: ?string, direct: bool, latest: ?string, latestCompatible: ?string, updateType: ?string, source: string, verifiedAt: ?string, scope: string}
 * @phpstan-type Finding array{fingerprint: string, type: string, severity: string, title: string, technology: string, current: ?string, target: ?string, source: string, verifiedAt: ?string, eol: ?string, confidence: int}
 */
final readonly class AnalysisResult
{
    /**
     * @param list<Technology> $technologies
     * @param list<Dependency> $dependencies
     * @param list<Finding>    $findings
     * @param list<string>     $errors
     */
    public function __construct(
        public array $technologies,
        public array $dependencies,
        public array $findings,
        public ?int $score,
        public int $confidence,
        public int $coverage,
        public array $errors,
    ) {
    }
}
