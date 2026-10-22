<?php

declare(strict_types=1);

namespace App\Infrastructure\Registry;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CatalogHttpClient
{
    /** @var array<string, int> */
    private array $blockedUntil = [];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array<array-key, mixed> */
    public function get(string $url): array
    {
        if (!in_array(parse_url($url, PHP_URL_HOST), ['repo.packagist.org', 'registry.npmjs.org', 'endoflife.date'], true) || 'https' !== parse_url($url, PHP_URL_SCHEME)) {
            throw new RegistryUnavailable('Source de catalogue non autorisée.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (($this->blockedUntil[$host] ?? 0) > time()) {
            throw new RegistryUnavailable('Catalogue temporairement indisponible ; reprise différée.');
        }
        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 8,
                'max_duration' => 15,
                'headers' => ['Accept' => 'application/json'],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > 8 * 1024 * 1024) {
                        throw new \RuntimeException('Réponse de catalogue trop volumineuse.');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                if (429 === $status || $status >= 500) {
                    $retry = $response->getHeaders(false)['retry-after'][0] ?? '';
                    $seconds = ctype_digit($retry) ? (int) $retry : 60;
                    $this->blockedUntil[$host] = time() + max(60, min(3600, $seconds));
                }
                $response->cancel();
                throw new RegistryUnavailable('Catalogue indisponible ou accès limité.');
            }
            $content = $response->getContent();
            if (strlen($content) > 8 * 1024 * 1024) {
                throw new RegistryUnavailable('Réponse de catalogue trop volumineuse.');
            }
            $data = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RegistryUnavailable('Réponse de catalogue invalide.');
            }

            return $data;
        } catch (\Throwable) {
            // External responses and URLs are deliberately absent from operational errors.
            throw new RegistryUnavailable('Catalogue indisponible : données non vérifiées.');
        }
    }
}
