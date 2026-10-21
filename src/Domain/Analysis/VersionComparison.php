<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Composer\Semver\Semver;

final class VersionComparison
{
    public static function stable(string $version): bool
    {
        return 1 === preg_match('/^v?\d+\.\d+\.\d+(?:\+[-\w.]+)?$/', $version);
    }

    /** @param list<string> $versions
     * @return array{latest: ?string, compatible: ?string, type: ?string}
     */
    public function compare(array $versions, ?string $declared, ?string $resolved): array
    {
        $versions = array_values(array_filter($versions, self::stable(...)));
        usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
        $compatible = null;
        if (null !== $declared && !preg_match('/(?:workspace:|file:|link:|https?:|git|npm:|latest|next)/i', $declared)) {
            foreach ($versions as $version) {
                try {
                    if (Semver::satisfies($version, $declared)) {
                        $compatible = $version;
                        break;
                    }
                } catch (\UnexpectedValueException) {
                    break;
                }
            }
        }
        $latest = $versions[0] ?? null;
        $type = null;
        if (null !== $resolved && self::stable($resolved) && null !== $latest && version_compare(ltrim($latest, 'v'), ltrim($resolved, 'v'), '>')) {
            $currentParts = explode('.', ltrim($resolved, 'v'));
            $latestParts = explode('.', ltrim($latest, 'v'));
            $type = $currentParts[0] !== $latestParts[0] ? 'major' : ($currentParts[1] !== $latestParts[1] ? 'minor' : 'patch');
        }

        return ['latest' => $latest, 'compatible' => $compatible, 'type' => $type];
    }
}
