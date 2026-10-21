<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Service\RotateEncryptionKey;
use App\Domain\Model\ProviderConnection;
use App\Infrastructure\Security\SecretCipher;
use App\Presentation\Command\RotateEncryptionKeyCommand;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EncryptionRotationTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $keyFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->keyFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testRotationUsesNewKeyAndOldKeyCanNoLongerReadTokens(): void
    {
        $id = $this->connection('synthetic-provider-token')->id;
        $oldCiphertext = $this->ciphertext($id);
        $service = new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher());
        self::assertSame(1, $service->rotate($this->keyFile($this->newKey()), true));
        $rotated = $this->ciphertext($id);
        self::assertNotSame($oldCiphertext, $rotated);
        self::assertSame('synthetic-provider-token', (new SecretCipher($this->newKey()))->decrypt($rotated));
        self::assertSame(1, $this->auditCount());
        $this->expectException(\RuntimeException::class);
        $this->oldCipher()->decrypt($rotated);
    }

    public function testDryRunChecksEveryCredentialWithoutMutatingDatabaseOrAudit(): void
    {
        $first = $this->connection('synthetic-first');
        $second = $this->connection('synthetic-second');
        $before = [$this->ciphertext($first->id), $this->ciphertext($second->id)];
        $service = new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher());
        self::assertSame(2, $service->rotate($this->keyFile($this->newKey())));
        self::assertSame($before, [$this->ciphertext($first->id), $this->ciphertext($second->id)]);
        self::assertSame(0, $this->auditCount());
    }

    public function testCorruptCredentialRollsBackEarlierRowsAndDoesNotLeakExceptionChain(): void
    {
        $first = $this->connection('synthetic-first');
        $second = $this->connection('synthetic-second');
        $broken = $this->connection('synthetic-broken');
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET encrypted_token = ? WHERE id = ?', ['invalid-credential', $broken->id]);
        $before = [$this->ciphertext($first->id), $this->ciphertext($second->id)];
        try {
            (new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher()))->rotate($this->keyFile($this->newKey()), true);
            self::fail('A corrupt record must stop the complete rotation.');
        } catch (\RuntimeException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('invalid-credential', $exception->getMessage());
            self::assertSame($before, [$this->ciphertext($first->id), $this->ciphertext($second->id)]);
            self::assertSame(0, $this->auditCount());
        }
    }

    public function testInvalidNewKeyIsRejectedBeforeMutation(): void
    {
        $connection = $this->connection('synthetic-unchanged');
        $before = $this->ciphertext($connection->id);
        try {
            (new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher()))->rotate($this->keyFile(base64_encode('too-short')), true);
            self::fail('The new key must contain exactly 32 bytes.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->ciphertext($connection->id));
        }
    }

    public function testCommandRequiresExecuteAndPrintsOnlyCountsWithoutSecrets(): void
    {
        $connection = $this->connection('synthetic-command-token');
        $before = $this->ciphertext($connection->id);
        $command = new CommandTester(new RotateEncryptionKeyCommand(new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher())));
        $file = $this->keyFile($this->newKey());
        self::assertSame(Command::SUCCESS, $command->execute(['--new-key-file' => $file]));
        self::assertSame($before, $this->ciphertext($connection->id));
        self::assertStringContainsString('Simulation : 1', $command->getDisplay());
        self::assertSame(Command::SUCCESS, $command->execute(['--new-key-file' => $file, '--execute' => true]));
        self::assertStringContainsString('1 connexion(s) rechiffrée(s)', $command->getDisplay());
        foreach ([$this->newKey(), base64_encode(str_repeat('o', 32)), 'synthetic-command-token'] as $secret) {
            self::assertStringNotContainsString($secret, $command->getDisplay());
        }
    }

    public function testCommandFailureDoesNotPrintCorruptDataOrKeys(): void
    {
        $connection = $this->connection('synthetic-command-token');
        $this->em->getConnection()->executeStatement('UPDATE provider_connection SET encrypted_token = ? WHERE id = ?', ['synthetic-corrupt-secret', $connection->id]);
        $command = new CommandTester(new RotateEncryptionKeyCommand(new RotateEncryptionKey($this->em->getConnection(), $this->oldCipher())));
        self::assertSame(Command::FAILURE, $command->execute(['--new-key-file' => $this->keyFile($this->newKey()), '--execute' => true]));
        self::assertStringNotContainsString('synthetic-corrupt-secret', $command->getDisplay());
        self::assertStringNotContainsString($this->newKey(), $command->getDisplay());
    }

    private function connection(string $token): ProviderConnection
    {
        $connection = new ProviderConnection();
        $connection->encryptedToken = $this->oldCipher()->encrypt($token);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function ciphertext(string $id): string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT encrypted_token FROM provider_connection WHERE id = ?', [$id]);
        self::assertIsString($value);

        return $value;
    }

    private function auditCount(): int
    {
        $count = $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'security.encryption_key_rotated'");
        if (is_int($count)) {
            return $count;
        }
        self::assertIsString($count);
        self::assertTrue(ctype_digit($count));

        return (int) $count;
    }

    private function oldCipher(): SecretCipher
    {
        return new SecretCipher(base64_encode(str_repeat('o', 32)));
    }

    private function newKey(): string
    {
        return base64_encode(str_repeat('n', 32));
    }

    private function keyFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'exopulse-key-test-');
        self::assertIsString($file);
        chmod($file, 0600);
        file_put_contents($file, $content."\n");
        $this->keyFiles[] = $file;

        return $file;
    }
}
