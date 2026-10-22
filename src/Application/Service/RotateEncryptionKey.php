<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Infrastructure\Security\SecretCipher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class RotateEncryptionKey
{
    public function __construct(private Connection $connection, private SecretCipher $oldCipher)
    {
    }

    public function rotate(string $newKeyFile, bool $execute = false): int
    {
        $newCipher = new SecretCipher($this->readKeyFile($newKeyFile));
        try {
            return $this->connection->transactional(function (Connection $connection) use ($newCipher, $execute): int {
                // The application is stopped for maintenance. This also blocks an accidental concurrent credential write.
                $connection->executeStatement('LOCK TABLE provider_connection IN SHARE ROW EXCLUSIVE MODE');
                $count = 0;
                foreach ($connection->iterateAssociative('SELECT id, encrypted_token FROM provider_connection ORDER BY id FOR UPDATE') as $row) {
                    if (!is_string($row['id']) || !is_string($row['encrypted_token'])) {
                        throw new \RuntimeException('Enregistrement de connexion invalide.');
                    }
                    $plaintext = $this->oldCipher->decrypt($row['encrypted_token']);
                    try {
                        $encrypted = $newCipher->encrypt($plaintext);
                        if (!hash_equals($plaintext, $newCipher->decrypt($encrypted))) {
                            throw new \RuntimeException('Vérification du chiffrement impossible.');
                        }
                    } finally {
                        sodium_memzero($plaintext);
                    }
                    if ($execute) {
                        $connection->executeStatement('UPDATE provider_connection SET encrypted_token = ?, updated_at = ? WHERE id = ?', [$encrypted, gmdate('Y-m-d H:i:s'), $row['id']]);
                    }
                    ++$count;
                }
                if ($execute) {
                    $now = gmdate('Y-m-d H:i:s');
                    $connection->insert('audit_event', ['id' => Uuid::v7()->toRfc4122(), 'created_at' => $now, 'updated_at' => $now, 'actor' => 'console', 'action' => 'security.encryption_key_rotated', 'subject_id' => 'provider-connections']);
                }

                return $count;
            });
        } catch (\Throwable) {
            throw new \RuntimeException('Rotation interrompue. Maintenez la maintenance et vérifiez la clé actuelle ainsi que l’état de la base avant toute reprise.');
        }
    }

    private function readKeyFile(string $path): string
    {
        $file = realpath($path);
        if (false === $file || !is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException('Le fichier local de nouvelle clé est absent ou illisible.');
        }
        $size = filesize($file);
        if (false === $size || $size > 256) {
            throw new \InvalidArgumentException('Le fichier de nouvelle clé est invalide.');
        }
        $content = file_get_contents($file, false, null, 0, 257);
        if (false !== $content && strlen($content) > 256) {
            throw new \InvalidArgumentException('Le fichier de nouvelle clé est invalide.');
        }
        $encoded = false === $content ? '' : trim($content);
        $decoded = base64_decode($encoded, true);
        if (false === $decoded || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($decoded)) {
            throw new \InvalidArgumentException('La nouvelle clé doit contenir 32 octets encodés en base64.');
        }
        sodium_memzero($decoded);

        return $encoded;
    }
}
