<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @phpstan-import-type Dependency from AnalysisResult */
final class JavascriptParser
{
    public function __construct(private readonly JavascriptLockParser $locks)
    {
    }

    /** @param array<string, string> $files
     * @return list<Dependency>
     */
    public function parse(array $files): array
    {
        $manifest = isset($files['package.json']) ? ManifestData::json($files['package.json']) : [];
        $dependencies = [];
        foreach (['dependencies' => 'prod', 'optionalDependencies' => 'optional', 'devDependencies' => 'dev', 'peerDependencies' => 'peer'] as $section => $scope) {
            foreach (ManifestData::map($manifest[$section] ?? null) as $name => $constraint) {
                if (is_string($name) && !isset($dependencies[$name])) {
                    $dependencies[$name] = ManifestData::dependency('npm', $name, ManifestData::text($constraint), null, true, 'package.json', $scope);
                }
            }
        }
        // A deterministic priority avoids merging incompatible resolutions from several package managers.
        foreach (['npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'] as $filename) {
            if (!isset($files[$filename])) {
                continue;
            }
            $constraints = [];
            foreach ($dependencies as $name => $dependency) {
                if (null !== $dependency['declared']) {
                    $constraints[$name] = $dependency['declared'];
                }
            }
            $resolved = $this->locks->parse($filename, $files[$filename], $constraints);
            foreach ($resolved as $entry) {
                $name = $entry['name'];
                $root = $entry['direct'] ? ($dependencies[$name] ?? null) : null;
                $key = $name.'@'.$entry['version'];
                if (isset($dependencies[$key]) && $dependencies[$key]['direct']) {
                    continue;
                }
                $dependencies[$key] = ManifestData::dependency('npm', $name, $root['declared'] ?? null, $entry['version'], null !== $root, $filename, $root['scope'] ?? 'unknown');
            }
            foreach (array_keys($dependencies) as $key) {
                if (isset($dependencies[$key]['declared']) && null === $dependencies[$key]['resolved']) {
                    foreach ($resolved as $entry) {
                        if ($entry['name'] === $key && $entry['direct']) {
                            unset($dependencies[$key]);
                            break;
                        }
                    }
                }
            }
            break;
        }

        return array_values($dependencies);
    }
}
