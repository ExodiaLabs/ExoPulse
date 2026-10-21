<?php

declare(strict_types=1);

$expected = $argv[1] ?? '';
if (!in_array($expected, ['messenger:consume', 'app:schedule'], true)) {
    exit(1);
}
$running = false;
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
    $command = @file_get_contents($file);
    if (false !== $command && str_contains($command, "bin/console\0".$expected)) {
        $running = true;
        break;
    }
}
if (!$running) {
    exit(1);
}
try {
    $url = parse_url((string) getenv('DATABASE_URL'));
    if (false === $url || !isset($url['host'], $url['path'], $url['user'])) {
        exit(1);
    }
    $database = ltrim($url['path'], '/');
    $connection = new PDO('pgsql:host='.$url['host'].';port='.($url['port'] ?? 5432).';dbname='.$database.';connect_timeout=3', rawurldecode($url['user']), rawurldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connection->query('SELECT 1');
} catch (Throwable) {
    exit(1);
}
