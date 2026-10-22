<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

final class SupportVersion
{
    public static function cycle(string $technology, string $version): ?string
    {
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.\d+)?$/', $version, $parts)) {
            return null;
        }

        return in_array($technology, ['Node.js', 'Vue'], true) ? $parts[1] : (isset($parts[2]) ? $parts[1].'.'.$parts[2] : null);
    }

    /** @return array{version: string, estimated: bool}|null */
    public static function select(string $technology, ?string $resolved, ?string $declared): ?array
    {
        $version = $resolved ?? $declared;
        if (null === $version) {
            return null;
        }
        if (preg_match('/^v?\d+(?:\.\d+){0,2}$/', $version)) {
            return ['version' => $version, 'estimated' => false];
        }
        // Since Symfony 3, x.4 is the final minor of its major release line.
        // This does not permit choosing an arbitrary cycle for ^6.0 or ^7.0.
        if (null === $resolved && in_array($technology, ['Symfony', 'Symfony (composants)'], true) && preg_match('/^\^([3-9]|[1-9]\d+)\.4(?:\.\d+)?$/', $version, $parts)) {
            return ['version' => $parts[1].'.4', 'estimated' => true];
        }
        if (null === $resolved && preg_match('/^(\d+\.\d+)\.(?:\*|x)$/i', $version, $parts)) {
            return ['version' => $parts[1], 'estimated' => true];
        }

        if (null === $resolved && preg_match('/^~(\d+\.\d+)\.\d+$/', $version, $parts)) {
            return ['version' => $parts[1], 'estimated' => true];
        }

        return null;
    }
}
