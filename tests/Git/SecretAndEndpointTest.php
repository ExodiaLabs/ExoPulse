<?php

declare(strict_types=1);

namespace App\Tests\Git;

use App\Domain\Git\ProviderException;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Security\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class SecretAndEndpointTest extends TestCase
{
    public function testCipherRandomizesAndAuthenticatesSecrets(): void
    {
        $cipher = new SecretCipher(base64_encode(random_bytes(32)));
        $first = $cipher->encrypt('private-token');
        self::assertNotSame($first, $cipher->encrypt('private-token'));
        self::assertSame('private-token', $cipher->decrypt($first));
        self::assertStringNotContainsString('private-token', $first);
        $bytes = base64_decode($first, true);
        self::assertIsString($bytes);
        $bytes[30] = chr(ord($bytes[30]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(base64_encode($bytes));
    }

    /** @return iterable<array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach (['http://gitlab.com', 'https://evil.test', 'https://gitlab.com.evil.test', 'https://token@gitlab.com', 'https://gitlab.com:443', 'https://gitlab.com?q=secret', 'https://gitlab.com/#fragment', 'https://127.0.0.1', 'https://gitlab.com\\@evil.test'] as $url) {
            yield [$url];
        }
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeBaseUrls(string $url): void
    {
        $this->expectException(ProviderException::class);
        (new GitEndpointPolicy('gitlab.com', ''))->validate($url, true);
    }

    public function testPrivateIpBlockedEvenWhenHostIsAllowlisted(): void
    {
        $url = 'https://127.0.0.1/api/v4/user';
        (new GitEndpointPolicy('127.0.0.1', ''))->validate($url);
        $client = new MockHttpClient(new MockResponse('{}'));
        $guard = new NoPrivateNetworkHttpClient($client);
        $this->expectException(TransportExceptionInterface::class);
        try {
            $guard->request('GET', $url)->getStatusCode();
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }
}
