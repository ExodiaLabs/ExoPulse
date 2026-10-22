<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SecretCipher
{
    public function __construct(#[Autowire('%env(EXOPULSE_ENCRYPTION_KEY)%')] #[\SensitiveParameter] private string $encodedKey)
    {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key()));
    }

    public function decrypt(#[\SensitiveParameter] string $ciphertext): string
    {
        $bytes = base64_decode($ciphertext, true);
        if (false === $bytes || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Identifiant chiffré invalide.');
        }
        $plaintext = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if (false === $plaintext) {
            throw new \RuntimeException('Impossible de déchiffrer cet identifiant.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $key = base64_decode($this->encodedKey, true);
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new \RuntimeException('La clé de chiffrement doit contenir 32 octets encodés en base64.');
        }

        return $key;
    }
}
