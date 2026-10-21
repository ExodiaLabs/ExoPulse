<?php

declare(strict_types=1);

namespace App\Tests\Analysis;

use App\Infrastructure\Registry\CatalogHttpClient;
use App\Infrastructure\Registry\RegistryCatalog;
use App\Infrastructure\Registry\RegistryUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RegistryCatalogTest extends TestCase
{
    public function testCacheAvoidsRepeatedRequestsAndPreservesVerificationDate(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertSame('https://repo.packagist.org/p2/vendor/package.json', $url);

            return new MockResponse('{"packages":{"vendor/package":[{"version":"1.0.0"},{"version":"2.0.0"}]}}');
        });
        $catalog = new RegistryCatalog(new CatalogHttpClient($client), new ArrayAdapter());
        $first = $catalog->versions('composer', 'vendor/package');
        self::assertSame($first, $catalog->versions('composer', 'vendor/package'));
        self::assertSame(1, $calls);
        self::assertSame(['1.0.0', '2.0.0'], $first['versions']);
    }

    public function testArbitrarySourceUrlNeverReachesTransport(): void
    {
        $client = new CatalogHttpClient(new MockHttpClient(static fn (): never => throw new \LogicException('Request must not run')));
        $this->expectException(RegistryUnavailable::class);
        $client->get('https://127.0.0.1/private');
    }

    public function testPackageNameCannotInjectAnotherUrl(): void
    {
        $catalog = new RegistryCatalog(new CatalogHttpClient(new MockHttpClient()), new ArrayAdapter());
        $this->expectException(RegistryUnavailable::class);
        $catalog->versions('npm', '../../private?token=secret');
    }

    public function testHttpFailuresNeverIncludeExternalResponseSecrets(): void
    {
        $client = new CatalogHttpClient(new MockHttpClient(new MockResponse('upstream token=secret', ['http_code' => 429])));
        try {
            $client->get('https://registry.npmjs.org/example');
            self::fail('An HTTP error must not become successful metadata.');
        } catch (RegistryUnavailable $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testRateLimitPausesFurtherRequestsToSameRegistryHost(): void
    {
        $calls = 0;
        $http = new CatalogHttpClient(new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After: 120']]);
        }));
        foreach (['one', 'two'] as $name) {
            try {
                $http->get('https://registry.npmjs.org/'.$name);
                self::fail('Rate limited metadata must not be accepted.');
            } catch (RegistryUnavailable) {
                // Both requests report unknown; only the first reaches the transport.
            }
        }
        self::assertSame(1, $calls);
    }

    public function testScopedNpmPackageUsesEncodedPath(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('https://registry.npmjs.org/%40scope%2Fpackage', $url);

            return new MockResponse('{"versions":{"1.0.0":{}}}');
        });
        $catalog = new RegistryCatalog(new CatalogHttpClient($client), new ArrayAdapter());
        self::assertSame(['1.0.0'], $catalog->versions('npm', '@scope/package')['versions']);
    }
}
