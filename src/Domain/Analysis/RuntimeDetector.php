<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/** @phpstan-import-type Technology from AnalysisResult
 * @phpstan-import-type Dependency from AnalysisResult
 */
final class RuntimeDetector
{
    /** @param array<string, string> $files
     * @param list<Dependency> $dependencies
     *
     * @return list<Technology>
     */
    public function detect(array $files, array $dependencies): array
    {
        $result = [];
        foreach (['.nvmrc' => 'Node.js', '.node-version' => 'Node.js', '.php-version' => 'PHP'] as $file => $name) {
            if (isset($files[$file])) {
                $result[] = $this->technology($name, trim($files[$file]), null, $file, 80);
            }
        }
        foreach (['composer.json' => ['require' => ['php' => 'PHP']], 'package.json' => ['engines' => ['node' => 'Node.js']]] as $file => $sections) {
            if (!isset($files[$file])) {
                continue;
            }
            $manifest = ManifestData::json($files[$file]);
            foreach ($sections as $section => $names) {
                $values = ManifestData::map($manifest[$section] ?? null);
                foreach ($names as $key => $name) {
                    $value = ManifestData::text($values[$key] ?? null);
                    if (null !== $value) {
                        $result[] = $this->technology($name, $value, null, $file, 70);
                    }
                }
            }
        }
        if (isset($files['Dockerfile'])) {
            preg_match_all('/^\s*FROM\s+(?:--platform=\S+\s+)?(?:[^\s\/]+\/)?(php|node):([^\s@]+)(?:@\S+)?/mi', $files['Dockerfile'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $tag = preg_replace('/-(?:alpine|bookworm|bullseye|slim|fpm|apache|cli).*/', '', $match[2]) ?? $match[2];
                $result[] = $this->technology('php' === strtolower($match[1]) ? 'PHP' : 'Node.js', $tag, null, 'Dockerfile', 60);
            }
        }
        foreach ($dependencies as $dependency) {
            $name = match ($dependency['name']) {
                'symfony/framework-bundle', 'symfony/symfony' => 'Symfony',
                'react' => 'React',
                'vue' => 'Vue',
                'typescript' => 'TypeScript',
                default => null,
            };
            if (null !== $name) {
                $result[] = $this->technology($name, $dependency['declared'], $dependency['resolved'], $dependency['source'], null !== $dependency['resolved'] ? 100 : 60);
            }
        }
        if (isset($files['composer.json']) || isset($files['composer.lock'])) {
            $result[] = $this->technology('Composer', null, null, isset($files['composer.lock']) ? 'composer.lock' : 'composer.json', 100);
        }
        foreach (['npm-shrinkwrap.json' => 'npm', 'package-lock.json' => 'npm', 'pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'Yarn'] as $file => $name) {
            if (isset($files[$file])) {
                $result[] = $this->technology($name, null, null, $file, 100);
            }
        }
        if (isset($files['package.json'])) {
            $result[] = $this->technology('JavaScript', null, null, 'package.json', 100);
        }

        return $result;
    }

    /** @return Technology */
    private function technology(string $name, ?string $declared, ?string $resolved, string $source, int $confidence): array
    {
        return compact('name', 'declared', 'resolved', 'source', 'confidence');
    }
}
