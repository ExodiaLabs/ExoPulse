<?php

declare(strict_types=1);

namespace App\Tests\Scheduling;

use PHPUnit\Framework\TestCase;

final class InitializeSecretsTest extends TestCase
{
    public function testInitializationIsPrivateIdempotentAndDoesNotPrintSecrets(): void
    {
        $root = sys_get_temp_dir().'/exopulse-initialize-'.bin2hex(random_bytes(8));
        mkdir($root.'/scripts', 0700, true);
        copy(\dirname(__DIR__, 2).'/scripts/initialize-secrets.php', $root.'/scripts/initialize-secrets.php');
        try {
            $output = $this->runScript($root);
            $first = file_get_contents($root.'/.env.local');
            self::assertIsString($first);
            self::assertStringContainsString('APP_SECRET=', $first);
            self::assertStringContainsString('EXOPULSE_ENCRYPTION_KEY=', $first);
            self::assertStringNotContainsString('ADMIN_PASSWORD', $first);
            self::assertSame(0600, fileperms($root.'/.env.local') & 0777);
            self::assertSame(1, preg_match("/APP_SECRET='([^']+)'/", $first, $match));
            $secret = $match[1] ?? '';
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $output);
            $this->runScript($root);
            self::assertSame($first, file_get_contents($root.'/.env.local'));
        } finally {
            foreach (['.env.local', 'var/initialize-secrets.lock', 'scripts/initialize-secrets.php'] as $file) {
                if (is_file($root.'/'.$file)) {
                    unlink($root.'/'.$file);
                }
            }
            if (is_dir($root.'/var')) {
                rmdir($root.'/var');
            }
            rmdir($root.'/scripts');
            rmdir($root);
        }
    }

    private function runScript(string $root): string
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $root.'/scripts/initialize-secrets.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['APP_SECRET' => '', 'EXOPULSE_ENCRYPTION_KEY' => '']);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), false === $error ? '' : $error);
        self::assertIsString($output);

        return $output;
    }
}
