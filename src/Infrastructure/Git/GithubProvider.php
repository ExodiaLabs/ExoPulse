<?php

declare(strict_types=1);

namespace App\Infrastructure\Git;

use App\Domain\Git\GitProvider;
use App\Domain\Git\ProviderException;
use App\Domain\Git\RemoteIssue;
use App\Domain\Git\RepositoryMetadata;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class GithubProvider implements GitProvider
{
    /** @var array<string,string> */
    private array $paths = [];

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
            foreach ($this->api->pages('' === $scope ? '/user/repos' : '/orgs/'.rawurlencode($scope).'/repos', ['sort' => 'full_name', 'direction' => 'asc']) as $row) {
                $id = GitApi::string($row, 'id');
                $name = GitApi::string($row, 'full_name');
                $this->paths[$id] = $name;
                yield new RepositoryMetadata($id, $name, GitApi::link($row, 'html_url'), is_string($row['default_branch'] ?? null) ? $row['default_branch'] : '', true === ($row['archived'] ?? false));
            }
        }
    }

    public function branches(string $externalId): array
    {
        $branches = [];
        foreach ($this->api->pages($this->path($externalId).'/branches') as $row) {
            $branches[] = GitApi::string($row, 'name');
        }

        return $branches;
    }

    public function head(string $externalId, string $branch): string
    {
        return GitApi::string($this->api->request($this->path($externalId).'/commits/'.rawurlencode($branch)) ?? [], 'sha');
    }

    public function file(string $externalId, string $path, string $ref): ?string
    {
        $row = $this->api->request($this->path($externalId).'/contents/'.implode('/', array_map('rawurlencode', explode('/', $path))), ['ref' => $ref], optional: true);
        if (null === $row) {
            return null;
        }
        if ('file' !== ($row['type'] ?? null) || 'base64' !== ($row['encoding'] ?? null)) {
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
        return $this->remoteIssue($this->api->request($this->path($externalId).'/issues', body: ['title' => $title, 'body' => $body]) ?? []);
    }

    public function issue(string $externalId, string $number): RemoteIssue
    {
        return $this->remoteIssue($this->api->request($this->path($externalId).'/issues/'.rawurlencode($number)) ?? []);
    }

    private function path(string $id): string
    {
        if (!isset($this->paths[$id])) {
            $this->paths[$id] = GitApi::string($this->api->request('/repositories/'.rawurlencode($id)) ?? [], 'full_name');
        }

        return '/repos/'.implode('/', array_map('rawurlencode', explode('/', $this->paths[$id])));
    }

    /** @param array<mixed> $row */
    private function remoteIssue(array $row): RemoteIssue
    {
        return new RemoteIssue(GitApi::string($row, 'number'), GitApi::link($row, 'html_url'), GitApi::string($row, 'state'));
    }
}
