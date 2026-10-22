<?php

declare(strict_types=1);

namespace App\Infrastructure\Git;

use App\Domain\Git\ProviderException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Exclude]
final class GitApi
{
    /** @param array<string,string> $headers */
    public function __construct(private HttpClientInterface $client, private GitEndpointPolicy $policy, private string $baseUrl, #[\SensitiveParameter] private array $headers)
    {
    }

    /**
     * @param array<string,mixed>      $query
     * @param array<string,mixed>|null $body
     *
     * @return array<mixed>|null
     */
    public function request(string $path, array $query = [], ?array $body = null, bool $optional = false): ?array
    {
        $url = $this->baseUrl.$path;
        $this->policy->validate($url);
        try {
            $response = $this->client->request(null === $body ? 'GET' : 'POST', $url, ['headers' => $this->headers, 'query' => $query, 'json' => $body, 'max_redirects' => 0, 'timeout' => 15, 'max_duration' => 30, 'extra' => ['trace_content' => false]]);
            $status = $response->getStatusCode();
            if ($optional && 404 === $status) {
                $response->cancel();

                return null;
            }
            if ($status < 200 || $status >= 300) {
                $headers = $response->getHeaders(false);
                $retry = $headers['retry-after'][0] ?? null;
                $retryAfter = null;
                if (null !== $retry) {
                    $retryAfter = ctype_digit($retry) ? (int) $retry : max(0, (strtotime($retry) ?: time()) - time());
                } elseif (isset($headers['x-ratelimit-reset'][0])) {
                    $retryAfter = max(0, (int) $headers['x-ratelimit-reset'][0] - time());
                }
                $response->cancel();
                throw new ProviderException(match ($status) {
                    401, 403 => 'Accès Git refusé ou quota épuisé.', 404 => 'Ressource Git inaccessible.', 429 => 'Quota Git épuisé.', default => 'Le fournisseur Git est indisponible.'
                }, $retryAfter, $status);
            }
            // Read a bounded response before parsing; never expose upstream bodies.
            $content = '';
            foreach ($this->client->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > 8 * 1024 * 1024) {
                    $response->cancel();
                    throw new ProviderException('Réponse Git trop volumineuse.');
                }
            }
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new ProviderException('Réponse Git invalide.');
            }

            return $data;
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ProviderException();
        }
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return iterable<array<mixed>>
     */
    public function pages(string $path, array $query = []): iterable
    {
        for ($page = 1; $page <= 10000; ++$page) {
            $rows = $this->request($path, $query + ['per_page' => 100, 'page' => $page]) ?? [];
            if (!array_is_list($rows)) {
                throw new ProviderException('Réponse de pagination Git invalide.');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ProviderException('Métadonnées Git invalides.');
                }
                yield $row;
            }
            if (count($rows) < 100) {
                return;
            }
        }
        throw new ProviderException('Limite de pagination Git atteinte.');
    }

    /** @param array<mixed> $data */
    public static function link(array $data, string $key): string
    {
        $url = self::string($data, $key);
        $parts = parse_url($url);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? '') || !isset($parts['host']) || isset($parts['user']) || preg_match('/[\\x00-\\x20\\\\]/', $url)) {
            throw new ProviderException('Lien Git invalide.');
        }

        return $url;
    }

    /** @param array<mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new ProviderException('Métadonnées Git incomplètes.');
        }

        return (string) $value;
    }
}
