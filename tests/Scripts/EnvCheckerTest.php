<?php

declare(strict_types=1);

namespace App\Tests\Scripts;

use PHPUnit\Framework\TestCase;

final class EnvCheckerTest extends TestCase
{
    public function testEmptyFilesStillProduceAHeader(): void
    {
        $directory = sys_get_temp_dir().'/exopulse-env-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory.'/.env', "# No variables yet\n");
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/scripts/env-checker.php').' --no-ansi '.escapeshellarg($directory);
            exec($command, $lines, $status);
            self::assertSame(0, $status);
            self::assertStringContainsString('Variable | .env', implode("\n", $lines));
        } finally {
            unlink($directory.'/.env');
            rmdir($directory);
        }
    }

    public function testReportsPresenceWithoutLeakingValues(): void
    {
        $directory = sys_get_temp_dir().'/exopulse-env-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory.'/.env', "TOKEN=secret-value\nexport SHARED=one\n# HIDDEN=no\n");
            file_put_contents($directory.'/.env.local', "SHARED=two\nLOCAL=private-value\n");
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/scripts/env-checker.php').' --no-ansi '.escapeshellarg($directory);
            exec($command, $lines, $status);
            $output = implode("\n", $lines);
            self::assertSame(0, $status);
            self::assertStringContainsString('TOKEN', $output);
            self::assertStringContainsString('✓ | ✗', $output);
            self::assertStringContainsString('✓ | ✓', $output);
            self::assertStringNotContainsString('secret-value', $output);
            self::assertStringNotContainsString('private-value', $output);
            self::assertStringNotContainsString('HIDDEN', $output);
        } finally {
            unlink($directory.'/.env');
            unlink($directory.'/.env.local');
            rmdir($directory);
        }
    }
}
