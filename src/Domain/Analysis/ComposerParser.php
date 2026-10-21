<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @phpstan-import-type Dependency from AnalysisResult */
final class ComposerParser
{
    /** @param array<string, string> $files
     * @return list<Dependency>
     */
    public function parse(array $files): array
    {
        $manifest = isset($files['composer.json']) ? ManifestData::json($files['composer.json']) : [];
        $declared = [];
        foreach (['require' => 'prod', 'require-dev' => 'dev'] as $section => $scope) {
            foreach (ManifestData::map($manifest[$section] ?? null) as $name => $constraint) {
                if (is_string($name) && str_contains($name, '/') && !isset($declared[$name])) {
                    $declared[$name] = ManifestData::dependency('composer', $name, ManifestData::text($constraint), null, true, 'composer.json', $scope);
                }
            }
        }
        $dependencies = $declared;
        if (isset($files['composer.lock'])) {
            $lock = ManifestData::json($files['composer.lock']);
            foreach (['packages' => 'prod', 'packages-dev' => 'dev'] as $section => $scope) {
                foreach (ManifestData::map($lock[$section] ?? null) as $entry) {
                    $package = ManifestData::map($entry);
                    $name = ManifestData::text($package['name'] ?? null);
                    if (null === $name) {
                        continue;
                    }
                    $dependencies[$name] = ManifestData::dependency('composer', $name, $declared[$name]['declared'] ?? null, ManifestData::text($package['version'] ?? null), isset($declared[$name]), 'composer.lock', $scope);
                }
            }
        }

        return array_values($dependencies);
    }
}
