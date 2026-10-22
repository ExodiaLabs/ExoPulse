<?php

declare(strict_types=1);

try {
    $url = parse_url((string) getenv('DATABASE_URL'));
    if (false === $url || !isset($url['host'], $url['path'], $url['user'])) {
        throw new RuntimeException();
    }
    $dsn = 'pgsql:host='.$url['host'].';port='.($url['port'] ?? 5432).';dbname='.ltrim($url['path'], '/').';connect_timeout=3';
    $connection = new PDO($dsn, rawurldecode($url['user']), rawurldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $privileged = $connection->query('SELECT CASE WHEN rolsuper OR rolcreatedb OR rolcreaterole THEN 1 ELSE 0 END FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    if (false === $privileged || 0 !== (int) $privileged) {
        fwrite(STDERR, "Runtime refused: the application database role must not have SUPERUSER, CREATEDB or CREATEROLE privileges.\n");
        exit(1);
    }
} catch (Throwable) {
    fwrite(STDERR, "Runtime refused: the application database configuration or connectivity could not be verified.\n");
    exit(1);
}
