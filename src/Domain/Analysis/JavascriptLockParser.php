<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Symfony\Component\Yaml\Yaml;

/** @phpstan-type Resolution array{name: string, version: string, direct: bool} */
final class JavascriptLockParser
{
    /** @param array<string, string> $constraints
     * @return list<Resolution>
     */
    public function parse(string $filename, string $content, array $constraints): array
    {
        return match ($filename) {
            'package-lock.json', 'npm-shrinkwrap.json' => $this->npm($content, $constraints),
            'pnpm-lock.yaml' => $this->pnpm($content, $constraints),
            'yarn.lock' => $this->yarn($content, $constraints),
            default => throw new \InvalidArgumentException('Format lockfile inconnu.'),
        };
    }

    /** @param array<string, string> $constraints
     * @return list<Resolution>
     */
    private function npm(string $content, array $constraints): array
    {
        $data = ManifestData::json($content);
        $format = $data['lockfileVersion'] ?? 1;
        if (!in_array($format, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Version package-lock non prise en charge.');
        }
        $result = [];
        if (isset($data['packages'])) {
            foreach (ManifestData::map($data['packages']) as $path => $value) {
                if (!is_string($path) || !str_contains($path, 'node_modules/')) {
                    continue;
                }
                $package = ManifestData::map($value);
                $offset = strrpos($path, 'node_modules/');
                $name = substr($path, false === $offset ? 0 : $offset + 13);
                $version = ManifestData::text($package['version'] ?? null);
                if (null !== $version && true !== ($package['link'] ?? false)) {
                    $result[] = ['name' => $name, 'version' => $version, 'direct' => isset($constraints[$name]) && $path === 'node_modules/'.$name];
                }
            }
        } else {
            $this->npmTree(ManifestData::map($data['dependencies'] ?? null), $constraints, $result, true);
        }

        return $result;
    }

    /** @param array<array-key, mixed> $tree
     * @param array<string, string> $constraints
     * @param list<Resolution>      $result
     */
    private function npmTree(array $tree, array $constraints, array &$result, bool $root, int $depth = 0): void
    {
        if ($depth > 40) {
            throw new \InvalidArgumentException('Lockfile npm trop imbriqué.');
        }
        foreach ($tree as $name => $value) {
            $package = ManifestData::map($value);
            $version = ManifestData::text($package['version'] ?? null);
            if (is_string($name) && null !== $version) {
                $result[] = ['name' => $name, 'version' => $version, 'direct' => $root && isset($constraints[$name])];
            }
            $this->npmTree(ManifestData::map($package['dependencies'] ?? null), $constraints, $result, false, $depth + 1);
        }
    }

    /** @param array<string, string> $constraints
     * @return list<Resolution>
     */
    private function pnpm(string $content, array $constraints): array
    {
        $data = ManifestData::map(Yaml::parse($content));
        $format = (string) (is_scalar($data['lockfileVersion'] ?? null) ? $data['lockfileVersion'] : '');
        if (!preg_match('/^(5|6|9)(\.|$)/', $format)) {
            throw new \InvalidArgumentException('Version pnpm-lock non prise en charge.');
        }
        $importers = ManifestData::map($data['importers'] ?? null);
        $root = ManifestData::map($importers['.'] ?? $data);
        $result = [];
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
            foreach (ManifestData::map($root[$section] ?? null) as $name => $value) {
                $entry = ManifestData::map($value);
                $version = ManifestData::text($entry['version'] ?? $value);
                if (is_string($name) && null !== $version && !preg_match('/^(link:|workspace:|file:)/', $version)) {
                    $version = preg_replace('/\(.*/', '', $version) ?? $version;
                    if (preg_match('~^/?(?:@[^/]+/)?[^/]+/([^/]+)$~', $version, $match)) {
                        $version = $match[1];
                    }
                    $result[] = ['name' => $name, 'version' => $version, 'direct' => isset($constraints[$name])];
                }
            }
        }
        foreach (ManifestData::map($data['packages'] ?? null) as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $key = ltrim($key, '/');
            if (preg_match('~^(@[^/]+/[^/@]+|[^/@]+)[/@](\d[^(_]*)(?:\(|_|$)~', $key, $match)) {
                $result[] = ['name' => $match[1], 'version' => $match[2], 'direct' => false];
            }
        }

        return $this->unique($result);
    }

    /** @param array<string, string> $constraints
     * @return list<Resolution>
     */
    private function yarn(string $content, array $constraints): array
    {
        $result = [];
        if (str_contains($content, '__metadata:')) {
            $data = ManifestData::map(Yaml::parse($content));
            foreach ($data as $selectors => $value) {
                $entry = ManifestData::map($value);
                $version = ManifestData::text($entry['version'] ?? null);
                if (is_string($selectors) && null !== $version) {
                    $this->yarnSelectors($selectors, $version, $constraints, $result);
                }
            }
        } else {
            $selectors = null;
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (preg_match('/^(\S.*):\s*$/', $line, $match) && !str_starts_with($line, '#')) {
                    $selectors = $match[1];
                } elseif (null !== $selectors && preg_match('/^\s+version\s+["\']?([^"\'\s]+)["\']?\s*$/', $line, $match)) {
                    $this->yarnSelectors($selectors, $match[1], $constraints, $result);
                }
            }
            if ([] === $result && '' !== trim(preg_replace('/^#.*$/m', '', $content) ?? '')) {
                throw new \InvalidArgumentException('Format Yarn non reconnu.');
            }
        }

        return $this->unique($result);
    }

    /** @param array<string, string> $constraints
     * @param list<Resolution> $result
     */
    private function yarnSelectors(string $selectors, string $version, array $constraints, array &$result): void
    {
        foreach (explode(', ', $selectors) as $selector) {
            $selector = trim($selector, "\"' ");
            if (preg_match('~^(@[^/]+/[^@]+|[^@]+)@(?:npm:)?(.+)$~', $selector, $match)) {
                $result[] = ['name' => $match[1], 'version' => $version, 'direct' => isset($constraints[$match[1]]) && $constraints[$match[1]] === $match[2]];
            }
        }
    }

    /** @param list<Resolution> $entries
     * @return list<Resolution>
     */
    private function unique(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $key = $entry['name'].'@'.$entry['version'];
            $entry['direct'] = $entry['direct'] || ($result[$key]['direct'] ?? false);
            $result[$key] = $entry;
        }

        return array_values($result);
    }
}
