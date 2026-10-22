<?php

declare(strict_types=1);

namespace App\Infrastructure\Git;

use App\Domain\Git\GitProvider;
use App\Domain\Git\ProviderException;
use App\Domain\Git\RemoteIssue;
use App\Domain\Git\RepositoryMetadata;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class GitlabProvider implements GitProvider
{
    public function __construct(private GitApi $api)
    {
    }

    public function test(): void
    {
        $this->api->request('/user');
    }

    public function repositories(array $scopes = []): iterable
    {
        foreach ([] === $scopes ? [''] : $scopes as $scope) {
            $query = '' === $scope ? ['membership' => true] : ['include_subgroups' => true];
            foreach ($this->api->pages('' === $scope ? '/projects' : '/groups/'.rawurlencode($scope).'/projects', $query + ['order_by' => 'id', 'sort' => 'asc']) as $row) {
                yield new RepositoryMetadata(GitApi::string($row, 'id'), GitApi::string($row, 'path_with_namespace'), GitApi::link($row, 'web_url'), is_string($row['default_branch'] ?? null) ? $row['default_branch'] : '', true === ($row['archived'] ?? false));
            }
        }
    }

    public function branches(string $externalId): array
    {
        $branches = [];
        foreach ($this->api->pages($this->path($externalId).'/repository/branches') as $row) {
            $branches[] = GitApi::string($row, 'name');
        }

        return $branches;
    }

    public function head(string $externalId, string $branch): string
    {
        return GitApi::string($this->api->request($this->path($externalId).'/repository/commits/'.rawurlencode($branch)) ?? [], 'id');
    }

    public function file(string $externalId, string $path, string $ref): ?string
    {
        $row = $this->api->request($this->path($externalId).'/repository/files/'.rawurlencode($path), ['ref' => $ref], optional: true);
        if (null === $row) {
            return null;
        }
        if ('base64' !== ($row['encoding'] ?? null)) {
            throw new ProviderException('Fichier Git non pris en charge.');
        }
        $content = base64_decode(str_replace(["\n", "\r"], '', GitApi::string($row, 'content')), true);
        if (false === $content) {
            throw new ProviderException('Contenu Git invalide.');
        }

        return $content;
    }

    public function createIssue(string $externalId, string $title, string $body): RemoteIssue
    {
        return $this->remoteIssue($this->api->request($this->path($externalId).'/issues', body: ['title' => $title, 'description' => $body]) ?? []);
    }

    public function issue(string $externalId, string $number): RemoteIssue
    {
        return $this->remoteIssue($this->api->request($this->path($externalId).'/issues/'.rawurlencode($number)) ?? []);
    }

    private function path(string $id): string
    {
        return '/projects/'.rawurlencode($id);
    }

    /** @param array<mixed> $row */
    private function remoteIssue(array $row): RemoteIssue
    {
        return new RemoteIssue(GitApi::string($row, 'iid'), GitApi::link($row, 'web_url'), 'opened' === GitApi::string($row, 'state') ? 'open' : GitApi::string($row, 'state'));
    }
}
