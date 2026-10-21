<?php

declare(strict_types=1);

// This helper only initializes the local deployment; no secret is printed.
$root = dirname(__DIR__);
$path = $root.'/.env.local';
if (!is_dir($root.'/var') && !mkdir($root.'/var', 0770, true) && !is_dir($root.'/var')) {
    fwrite(STDERR, "Impossible de créer le répertoire local de verrouillage.\n");
    exit(1);
}
$lock = fopen($root.'/var/initialize-secrets.lock', 'c');
if (false === $lock || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Impossible de verrouiller l’initialisation des secrets.\n");
    exit(1);
}
try {
    if (is_link($path)) {
        throw new RuntimeException('Le fichier local de secrets ne doit pas être un lien symbolique.');
    }
    $exists = is_file($path);
    $content = $exists ? file_get_contents($path) : '';
    if (false === $content) {
        throw new RuntimeException('Le fichier local de secrets est illisible.');
    }
    $changed = false;
    foreach (['APP_SECRET', 'EXOPULSE_ENCRYPTION_KEY'] as $name) {
        $environment = getenv($name);
        if (false !== $environment && '' !== trim($environment)) {
            continue;
        }
        if (preg_match('/^(?:export\s+)?'.preg_quote($name, '/').'\s*=\s*(.*)$/m', $content, $match) && '' !== trim($match[1], " \t\r\n\"'")) {
            continue;
        }
        $value = 'APP_SECRET' === $name ? bin2hex(random_bytes(32)) : base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $line = $name."='".$value."'";
        if (preg_match('/^(?:export\s+)?'.preg_quote($name, '/').'\s*=.*$/m', $content)) {
            $content = preg_replace('/^(?:export\s+)?'.preg_quote($name, '/').'\s*=.*$/m', $line, $content) ?? throw new RuntimeException('Écriture des secrets impossible.');
        } else {
            $content = rtrim($content)."\n".$line."\n";
        }
        $changed = true;
    }
    if ($exists && !chmod($path, 0600)) {
        throw new RuntimeException('Impossible de protéger le fichier local.');
    }
    if ($changed) {
        $previousMask = umask(0077);
        try {
            if (false === file_put_contents($path, $content, LOCK_EX)) {
                throw new RuntimeException('Écriture des secrets impossible.');
            }
        } finally {
            umask($previousMask);
        }
    }
    if (is_file($path) && !chmod($path, 0600)) {
        throw new RuntimeException('Impossible de protéger les permissions du fichier local.');
    }
    fwrite(STDOUT, "Configuration des secrets locaux vérifiée. Aucune valeur affichée.\n");
} catch (Throwable) {
    fwrite(STDERR, "Initialisation des secrets impossible. Vérifiez les permissions des fichiers locaux.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
