<?php

declare(strict_types=1);

namespace App\Tests\Git;

use App\Domain\Git\ProviderException;
use App\Infrastructure\Git\GitApi;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Git\GithubProvider;
use App\Infrastructure\Git\GitlabProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitProviderTest extends TestCase
{
    public function testFactoryBlocksPrivateNetworkBeforeSendingCredentials(): void
    {
        $cipher = new \App\Infrastructure\Security\SecretCipher(base64_encode(random_bytes(32)));
        $connection = new \App\Domain\Model\ProviderConnection();
        $connection->provider = 'gitlab';
        $connection->baseUrl = 'https://127.0.0.1';
        $connection->encryptedToken = $cipher->encrypt('synthetic-test-token');
        $client = new MockHttpClient(new MockResponse('{}'));
        $factory = new \App\Infrastructure\Git\GitProviderFactory($client, $cipher, new GitEndpointPolicy('127.0.0.1', ''));
        $this->expectException(ProviderException::class);
        try {
            $factory->forConnection($connection)->test();
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testGithubPaginationAndResolvedContent(): void
    {
        $row = ['id' => 7, 'full_name' => 'org/legacy', 'html_url' => 'https://github.com/org/legacy', 'default_branch' => 'main', 'archived' => true];
        $client = new MockHttpClient([
            new MockResponse(json_encode(array_fill(0, 100, $row), JSON_THROW_ON_ERROR)),
            new MockResponse('[]'),
            new MockResponse('{"sha":"commit-123"}'),
            new MockResponse('{"type":"file","encoding":"base64","content":"e30="}'),
            new MockResponse('', ['http_code' => 404]),
        ]);
        $provider = new GithubProvider(new GitApi($client, new GitEndpointPolicy('api.github.com', ''), 'https://api.github.com', ['Authorization' => 'Bearer secret']));
        $repositories = iterator_to_array($provider->repositories(['org']), false);
        self::assertCount(100, $repositories);
        self::assertSame('7', $repositories[0]->externalId);
        self::assertTrue($repositories[0]->archived);
        self::assertSame('commit-123', $provider->head('7', 'main'));
        self::assertSame('{}', $provider->file('7', 'composer.json', 'commit-123'));
        self::assertNull($provider->file('7', 'package.json', 'commit-123'));
        self::assertSame(5, $client->getRequestsCount());
    }

    public function testGitlabBranchesAndIssueOperations(): void
    {
        $responses = [
            new MockResponse('[{"name":"main"},{"name":"release/1"}]'),
            new MockResponse('{"iid":9,"web_url":"https://gitlab.com/org/app/-/issues/9","state":"opened"}'),
            new MockResponse('{"iid":9,"web_url":"https://gitlab.com/org/app/-/issues/9","state":"closed"}'),
        ];
        $provider = new GitlabProvider(new GitApi(new MockHttpClient($responses), new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', ['PRIVATE-TOKEN' => 'secret']));
        self::assertSame(['main', 'release/1'], $provider->branches('42'));
        self::assertSame('open', $provider->createIssue('42', 'Migration', 'Contexte')->state);
        self::assertSame('closed', $provider->issue('42', '9')->state);
    }

    public function testGitlabScopedDiscoveryCommitAndFileUseEncodedPaths(): void
    {
        $responses = [
            new MockResponse('{"id":1}'),
            new MockResponse('[{"id":42,"path_with_namespace":"org/app","web_url":"https://gitlab.com/org/app","default_branch":"legacy","archived":false}]'),
            new MockResponse('{"id":"abc123"}'),
            new MockResponse('{"encoding":"base64","content":"e30="}'),
        ];
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });
        $provider = new GitlabProvider(new GitApi($client, new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/subpath/api/v4', ['PRIVATE-TOKEN' => 'private-token']));
        $provider->test();
        $repositories = iterator_to_array($provider->repositories(['org/sub']), false);
        self::assertSame('42', $repositories[0]->externalId);
        self::assertSame('abc123', $provider->head('42', 'release/1'));
        self::assertSame('{}', $provider->file('42', 'nested/composer.json', 'abc123'));
        self::assertStringContainsString('/groups/org%2Fsub/projects?', $requests[1][1]);
        self::assertStringContainsString('include_subgroups=1', $requests[1][1]);
        self::assertStringContainsString('/commits/release%2F1', $requests[2][1]);
        self::assertStringContainsString('/files/nested%2Fcomposer.json?ref=abc123', $requests[3][1]);
        self::assertSame(0, $requests[0][2]['max_redirects']);
        self::assertIsArray($requests[0][2]['headers']);
        self::assertContains('PRIVATE-TOKEN: private-token', $requests[0][2]['headers']);
    }

    public function testRedirectIsRejectedWithoutFollowingLocation(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://127.0.0.1/private']]));
        $api = new GitApi($client, new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', []);
        try {
            $api->request('/user');
            self::fail('Expected redirect rejection');
        } catch (ProviderException $error) {
            self::assertSame(302, $error->status);
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testMalformedJsonIsUnavailableWithoutBodyLeak(): void
    {
        $api = new GitApi(new MockHttpClient(new MockResponse('private-token not json')), new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', []);
        try {
            $api->request('/user');
            self::fail('Expected invalid JSON');
        } catch (ProviderException $error) {
            self::assertStringNotContainsString('private-token', (string) $error);
        }
    }

    public function testRateLimitProvidesRetryWithoutExposingBody(): void
    {
        $api = new GitApi(new MockHttpClient(new MockResponse('secret-token server failure', ['http_code' => 429, 'response_headers' => ['Retry-After: 60']])), new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', []);
        try {
            $api->request('/user');
            self::fail('Expected rate limit');
        } catch (ProviderException $error) {
            self::assertSame(60, $error->retryAfter);
            self::assertSame(429, $error->status);
            self::assertStringNotContainsString('secret-token', (string) $error);
        }
    }

    /** @return iterable<array{int}> */
    public static function failureStatuses(): iterable
    {
        foreach ([401, 403, 404, 500, 503] as $status) {
            yield [$status];
        }
    }

    #[DataProvider('failureStatuses')]
    public function testUpstreamFailuresPreserveStatusWithoutBody(int $status): void
    {
        $api = new GitApi(new MockHttpClient(new MockResponse('private-token', ['http_code' => $status])), new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', []);
        try {
            $api->request('/user');
            self::fail('Expected upstream failure');
        } catch (ProviderException $error) {
            self::assertSame($status, $error->status);
            self::assertStringNotContainsString('private-token', (string) $error);
        }
    }

    public function testGithubPermissionRefusalDoesNotBecomeQuotaRetry(): void
    {
        $reset = (string) (time() + 600);
        $api = new GitApi(new MockHttpClient(new MockResponse('permission denied', ['http_code' => 403, 'response_headers' => ['X-RateLimit-Remaining: 42', 'X-RateLimit-Reset: '.$reset]])), new GitEndpointPolicy('api.github.com', ''), 'https://api.github.com', []);
        try {
            $api->request('/user');
            self::fail('Expected permission refusal');
        } catch (ProviderException $error) {
            self::assertSame(403, $error->status);
            self::assertNull($error->retryAfter);
        }
    }

    public function testGithubExhaustedQuotaProvidesResetDelay(): void
    {
        $reset = (string) (time() + 600);
        $api = new GitApi(new MockHttpClient(new MockResponse('quota exceeded', ['http_code' => 403, 'response_headers' => ['X-RateLimit-Remaining: 0', 'X-RateLimit-Reset: '.$reset]])), new GitEndpointPolicy('api.github.com', ''), 'https://api.github.com', []);
        try {
            $api->request('/user');
            self::fail('Expected exhausted quota');
        } catch (ProviderException $error) {
            self::assertSame(403, $error->status);
            self::assertNotNull($error->retryAfter);
            self::assertGreaterThan(0, $error->retryAfter);
            self::assertLessThanOrEqual(600, $error->retryAfter);
        }
    }

    public function testNetworkErrorsAreSanitizedWithoutPreviousException(): void
    {
        $client = new MockHttpClient(static function (): never { throw new \RuntimeException('https://secret-token@host'); });
        $api = new GitApi($client, new GitEndpointPolicy('gitlab.com', ''), 'https://gitlab.com/api/v4', []);
        try {
            $api->request('/user');
            self::fail('Expected failure');
        } catch (ProviderException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret-token', (string) $error);
        }
    }
}
