<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @phpstan-import-type Dependency from AnalysisResult
 * @phpstan-import-type Technology from AnalysisResult
 * @phpstan-import-type Finding from AnalysisResult
 */
final class AnalysisEngine
{
    public function __construct(
        private readonly ComposerParser $composer,
        private readonly JavascriptParser $javascript,
        private readonly RuntimeDetector $runtimes,
        private readonly PackageCatalog $packages,
        private readonly SupportCatalog $support,
        private readonly HealthPolicy $health,
        private readonly VersionComparison $versions,
    ) {
    }

    /** @param array<string, string> $files
     * @param array<string, int> $weights
     */
    public function analyse(array $files, bool $refresh = true, array $weights = []): AnalysisResult
    {
        $errors = [];
        $safeFiles = [];
        foreach ($files as $name => $content) {
            if (strlen($content) > 2 * 1024 * 1024) {
                $errors[] = $name.' : fichier trop volumineux, analyse partielle.';
            } else {
                $safeFiles[$name] = $content;
            }
        }
        $dependencies = [];
        foreach ([$this->composer, $this->javascript] as $parser) {
            try {
                array_push($dependencies, ...$parser->parse($safeFiles));
            } catch (\Throwable) {
                $errors[] = $parser instanceof ComposerParser ? 'Manifestes Composer non interprétables.' : 'Manifestes JavaScript non interprétables.';
                // Keep the declared inventory if a lockfile is malformed.
                $manifests = array_intersect_key($safeFiles, array_flip(['composer.json', 'package.json']));
                try {
                    array_push($dependencies, ...$parser->parse($manifests));
                } catch (\Throwable) {
                    // A malformed root manifest cannot yield confirmed dependencies.
                }
            }
        }
        try {
            $technologies = $this->runtimes->detect($safeFiles, $dependencies);
        } catch (\Throwable) {
            $technologies = $this->runtimes->detect(array_diff_key($safeFiles, array_flip(['composer.json', 'package.json'])), $dependencies);
            $errors[] = 'Détection des intentions de runtime partielle.';
        }
        $findings = [];
        $checks = 0;
        $confirmed = 0;
        foreach ($dependencies as &$dependency) {
            if (!$dependency['direct']) {
                continue;
            }
            ++$checks;
            if (!$refresh) {
                continue;
            }
            try {
                $catalog = $this->packages->versions($dependency['ecosystem'], $dependency['name']);
                $comparison = $this->versions->compare($catalog['versions'], $dependency['declared'], $dependency['resolved']);
                $dependency['latest'] = $comparison['latest'];
                $dependency['latestCompatible'] = $comparison['compatible'];
                $dependency['updateType'] = $comparison['type'];
                $dependency['verifiedAt'] = $catalog['verifiedAt'];
                $dependency['source'] .= ' | '.$catalog['source'];
                if (null !== $dependency['resolved'] && VersionComparison::stable($dependency['resolved']) && null !== $comparison['latest']) {
                    ++$confirmed;
                }
                if (null !== $comparison['type']) {
                    $findings[] = $this->finding($comparison['type'], 'major' === $comparison['type'] ? 'warning' : 'info', $dependency['name'].' : mise à jour '.$comparison['type'].' disponible ; compatibilité à vérifier', $dependency['name'], $dependency['resolved'], $comparison['latest'], $catalog['source'], $catalog['verifiedAt'], null, 100);
                }
            } catch (\Throwable) {
                $errors[] = $dependency['name'].' : registre indisponible, version récente inconnue.';
            }
        }
        unset($dependency);
        foreach ($technologies as &$technology) {
            if ('language' === ($technology['kind'] ?? '') || (!in_array($technology['name'], ['PHP', 'Node.js', 'Symfony', 'React', 'Vue'], true) && 'Symfony (composants)' !== $technology['name'])) {
                continue;
            }
            ++$checks;
            $technology['supportStatus'] = 'unknown';
            $technology['supportConfidence'] = 0;
            $version = $technology['resolved'] ?? $technology['declared'];
            $selection = SupportVersion::select($technology['name'], $technology['resolved'], $technology['declared']);
            $lifecycle = null;
            $origin = 'bundled';
            try {
                // Verified publisher snapshots take precedence over the community
                // aggregator, which may not yet reflect a support extension.
                if (null !== $selection && $this->support instanceof OfflineSupportCatalog) {
                    $lifecycle = $this->support->lookupOffline($technology['name'], $selection['version']);
                }
                if (null === $lifecycle && null !== $selection && $refresh) {
                    $lifecycle = $this->support->lookup($technology['name'], $selection['version']);
                    $origin = 'online';
                }
                if (null === $lifecycle || null === $lifecycle['eol'] || null === $selection) {
                    $findings[] = $this->finding('unknown', 'info', $technology['name'].' : échéance de support inconnue', $technology['name'], $version, null, $technology['source'], null, null, 0);
                    continue;
                }
                $supportConfidence = $selection['estimated'] ? min(60, $technology['confidence']) : $technology['confidence'];
                if (!$selection['estimated']) {
                    ++$confirmed;
                }
                $technology['supportStatus'] = $selection['estimated'] ? 'estimated' : 'known';
                $technology['supportConfidence'] = $supportConfidence;
                $technology['supportOrigin'] = $origin;
                $technology['supportDatePrecision'] = $lifecycle['precision'] ?? 'day';
                $technology['supportCycle'] = SupportVersion::cycle($technology['name'], $selection['version']);
                $technology['eol'] = $lifecycle['eol'];
                $technology['endOfMaintenance'] = $lifecycle['endOfMaintenance'];
                $technology['supportSource'] = $lifecycle['source'];
                $technology['supportVerifiedAt'] = $lifecycle['verifiedAt'];
                $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
                $type = $lifecycle['eol'] < $today ? 'end_of_support' : (null !== $lifecycle['endOfMaintenance'] && $lifecycle['endOfMaintenance'] < $today ? 'end_of_maintenance' : null);
                if (null !== $type) {
                    $isRuntime = in_array($technology['name'], ['PHP', 'Node.js'], true);
                    $title = $technology['name'].($selection['estimated'] ? ' : estimation selon la contrainte déclarée' : '').('end_of_support' === $type ? ' : série hors support de sécurité' : ' : maintenance active terminée').($isRuntime ? ' selon la configuration ; runtime déployé à vérifier' : '').' ; cible à valider pour compatibilité';
                    $findings[] = $this->finding($type, 'end_of_support' === $type ? 'critical' : 'warning', $title, $technology['name'], $version, $lifecycle['target'], $lifecycle['source'], $lifecycle['verifiedAt'], $lifecycle['eol'], $supportConfidence);
                }
            } catch (\Throwable) {
                $errors[] = $technology['name'].' : calendrier de support indisponible.';
            }
        }
        unset($technology);
        $uniqueFindings = [];
        foreach ($findings as $finding) {
            $existing = $uniqueFindings[$finding['fingerprint']] ?? null;
            if (null === $existing || $existing['confidence'] < $finding['confidence']) {
                $uniqueFindings[$finding['fingerprint']] = $finding;
            }
        }
        $findings = array_values($uniqueFindings);
        $known = count(array_filter($dependencies, static fn (array $dependency): bool => null !== $dependency['resolved'] && VersionComparison::stable($dependency['resolved'])));
        $coverage = [] === $dependencies ? ([] === $technologies ? 0 : 50) : (int) round(100 * $known / count($dependencies));
        if ([] !== $errors) {
            $coverage = min($coverage, 75);
        }
        $confidence = 0 === $checks ? 0 : (int) round(100 * $confirmed / $checks);

        return new AnalysisResult($technologies, $dependencies, $findings, $this->health->score($findings, $coverage, $confidence, $weights), $confidence, $coverage, array_values(array_unique($errors)));
    }

    /** @return Finding */
    private function finding(string $type, string $severity, string $title, string $technology, ?string $current, ?string $target, string $source, ?string $verifiedAt, ?string $eol, int $confidence): array
    {
        $fingerprint = hash('sha256', $type.'|'.$technology.'|'.($current ?? 'unknown'));

        return compact('fingerprint', 'type', 'severity', 'title', 'technology', 'current', 'target', 'source', 'verifiedAt', 'eol', 'confidence');
    }
}
