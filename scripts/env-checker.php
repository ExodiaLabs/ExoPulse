<?php

declare(strict_types=1);

// Deliberately reads names only: values must never reach terminal output.
$arguments = array_slice($argv, 1);
$ansi = in_array('--ansi', $arguments, true) || (!in_array('--no-ansi', $arguments, true) && stream_isatty(STDOUT));
$directories = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
$directory = $directories[0] ?? dirname(__DIR__);
$files = glob(rtrim($directory, '/').'/.env*') ?: [];
$files = array_values(array_filter($files, 'is_file'));
sort($files);
if ([] === $files) {
    fwrite(STDERR, "Aucun fichier .env trouvé.\n");
    exit(1);
}
$variables = [];
foreach ($files as $index => $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        fwrite(STDERR, "Impossible de lire un fichier de configuration.\n");
        exit(1);
    }
    foreach ($lines as $line) {
        if (1 === preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches)) {
            $variables[$matches[1]][$index] = true;
        }
    }
}
ksort($variables);
$width = max([8, ...array_map('strlen', array_keys($variables))]);
echo str_pad('Variable', $width).' | '.implode(' | ', array_map('basename', $files)).PHP_EOL;
foreach ($variables as $name => $presence) {
    $cells = [];
    foreach ($files as $index => $file) {
        $present = isset($presence[$index]);
        $symbol = $present ? '✓' : '✗';
        $cells[] = $ansi ? ($present ? "\033[32m" : "\033[31m").$symbol."\033[0m" : $symbol;
    }
    echo str_pad($name, $width).' | '.implode(' | ', $cells).PHP_EOL;
}
