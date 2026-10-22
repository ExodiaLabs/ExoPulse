<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Git\ProviderException;
use App\Domain\Git\RemoteIssue;
use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\Repository;
use App\Infrastructure\Git\GitProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

final class IssueManager
{
    public function __construct(private EntityManagerInterface $em, private GitProviderFactory $providers, private LockFactory $locks, private AuditTrail $audit, private RepositoryScope $scope)
    {
    }

    /** @param list<string> $ids */
    public function preview(array $ids): IssueDraft
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids || count($ids) > 50) {
            throw new \InvalidArgumentException('Sélectionnez entre 1 et 50 constats.');
        }
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw new \InvalidArgumentException('Identifiant de constat invalide.');
            }
        }
        $findings = $this->em->getRepository(Finding::class)->findBy(['id' => $ids], ['id' => 'ASC']);
        if (count($findings) !== count($ids)) {
            throw new \OutOfBoundsException('Constat introuvable.');
        }
        $repository = $findings[0]->repository;
        foreach ($findings as $finding) {
            if ($finding->repository->id !== $repository->id || null !== $finding->resolvedAt) {
                throw new \InvalidArgumentException('Sélectionnez des constats actifs d’une même application.');
            }
        }
        $fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint, $findings);
        sort($fingerprints);
        $fingerprint = hash('sha256', implode("\n", $fingerprints));
        $existing = $this->existing($repository->id, $fingerprint, $fingerprints);
        $title = mb_substr('Migration technique : '.$repository->name, 0, 255);
        $lines = ['## Contexte', '', 'Application : '.$repository->name, 'Les branches concernées sont précisées avec chaque constat.', ''];
        foreach ($findings as $finding) {
            $lines[] = '- '.$finding->title.' — '.$this->detail($finding, 'current').' → '.$this->detail($finding, 'target').' (branche : '.$this->detail($finding, 'branch').'). Source : '.$this->detail($finding, 'source').'. Vérification : '.$this->detail($finding, 'verifiedAt');
        }
        $body = implode("\n", $lines)."\n\n## Objectif\n\nRésoudre les constats sélectionnés et rétablir le support technique.\n\n## Travail attendu\n\n- Vérifier les versions cibles, leur support et les guides de migration officiels.\n- Pour une mise à jour majeure, vérifier les compatibilités du runtime, du framework et des dépendances avant toute migration.\n- Adapter le code et les configurations nécessaires.\n\n## Critères d'acceptation\n\n- [ ] Les versions actuelles et cibles sont confirmées.\n- [ ] Les tests, l’analyse statique et le build réussissent.\n- [ ] Un nouveau scan confirme le traitement des constats sélectionnés.\n";

        return new IssueDraft($repository, $findings, $title, $body, $fingerprint, $existing);
    }

    /** @param list<string> $ids */
    public function create(array $ids, string $title, string $body, string $actor): IssueTracking
    {
        $draft = $this->preview($ids);
        if ('' === trim($title) || mb_strlen($title) > 255 || '' === trim($body) || mb_strlen($body) > 60000) {
            throw new \InvalidArgumentException('Le titre ou le contenu de l’issue est invalide.');
        }
        foreach (['## Contexte', '## Objectif', '## Travail attendu', "## Critères d'acceptation"] as $section) {
            if (!str_contains($body, $section)) {
                throw new \InvalidArgumentException('Conservez les quatre sections obligatoires du contenu.');
            }
        }
        $repository = $draft->repository;
        $lock = $this->locks->createLock('issue-create-'.$repository->id, 120);
        if (!$lock->acquire()) {
            throw new \InvalidArgumentException('Une création d’issue est déjà en cours.');
        }
        try {
            $this->refreshScope($repository);
            if (!$repository->accessible || $repository->archived) {
                throw new \DomainException('La création d’issue est indisponible pour cette application.');
            }
            $draft = $this->preview($ids);
            $issue = $draft->existingIssue;
            if (null !== $issue && 'failed' !== $issue->status) {
                return $issue;
            }
            try {
                $provider = $this->providers->forConnection($repository->connection);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('La connexion Git doit être configurée avant la création.');
            }
            $issue ??= new IssueTracking();
            $issue->repository = $repository;
            $issue->fingerprint = $draft->fingerprint;
            $issue->fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint, $draft->findings);
            $issue->status = 'creating';
            $issue->lastError = null;
            $this->em->persist($issue);
            $this->audit->record('issue.creation_requested', $issue->id, $actor);
            // Durable reservation before POST: an uncertain response must never trigger a retry.
            $this->em->flush();
            try {
                $remote = $provider->createIssue($repository->externalId, trim($title), $body."\n\n<!-- exopulse:".$issue->fingerprint.' -->');
                $this->acceptRemote($issue, $remote);
                $this->audit->record('issue.created', $issue->id, $actor);
            } catch (\Throwable $error) {
                $issue->status = $error instanceof ProviderException && null !== $error->status && $error->status >= 400 && $error->status < 500 && 408 !== $error->status ? 'failed' : 'unknown';
                $issue->lastError = 'unknown' === $issue->status ? 'Résultat incertain : vérifiez le fournisseur et associez le numéro de l’issue avant toute nouvelle tentative.' : 'Création refusée par le fournisseur. Vérifiez les droits et le quota avant une nouvelle tentative manuelle.';
                $this->audit->record('issue.creation_'.$issue->status, $issue->id, $actor);
            }
            $this->em->flush();

            return $issue;
        } finally {
            $lock->release();
        }
    }

    public function refresh(IssueTracking $issue, string $actor, ?string $number = null, bool $retryOnFailure = false): void
    {
        $lock = $this->locks->createLock('issue-create-'.$issue->repository->id, 120);
        if (!$lock->acquire()) {
            throw new \InvalidArgumentException('Une opération sur les issues est déjà en cours.');
        }
        try {
            $this->em->refresh($issue);
            $this->refreshScope($issue->repository);
            if ($retryOnFailure) {
                if (null !== $issue->lastSyncedAt && $issue->lastSyncedAt > new \DateTimeImmutable('-5 minutes')) {
                    return;
                }
            }
            $number = '' !== $issue->number ? $issue->number : trim($number ?? '');
            if (!preg_match('/^[1-9][0-9]{0,19}$/', $number)) {
                throw new \InvalidArgumentException('Renseignez un numéro d’issue valide pour réconcilier le résultat.');
            }
            $failure = null;
            try {
                $remote = $this->providers->forConnection($issue->repository->connection)->issue($issue->repository->externalId, $number);
                if ($remote->number !== $number) {
                    throw new ProviderException('Réponse de statut incohérente.');
                }
                $this->acceptRemote($issue, $remote);
                $issue->lastError = null;
                $this->audit->record('issue.refreshed', $issue->id, $actor);
            } catch (\Throwable $error) {
                $failure = $error instanceof ProviderException ? $error : new ProviderException();
                $issue->lastError = 'Impossible de vérifier le statut auprès du fournisseur.';
                $this->audit->record('issue.refresh_failed', $issue->id, $actor);
            }
            $this->em->flush();
            if ($retryOnFailure && null !== $failure) {
                throw $failure;
            }
        } finally {
            $lock->release();
        }
    }

    private function refreshScope(Repository $repository): void
    {
        $this->em->refresh($repository);
        $this->em->refresh($repository->connection);
        if (!$repository->connection->active || !$repository->monitored || !$this->scope->includes($repository->connection, $repository->name)) {
            throw new \DomainException('Cette application ne fait plus partie du périmètre Git actif.');
        }
    }

    private function acceptRemote(IssueTracking $issue, RemoteIssue $remote): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/', $remote->number) || !in_array($remote->state, ['open', 'closed'], true)) {
            throw new ProviderException('Réponse d’issue incohérente.');
        }
        $issue->number = $remote->number;
        $issue->url = $remote->url;
        $issue->status = $remote->state;
        $issue->lastSyncedAt = new \DateTimeImmutable();
        $issue->nextRefreshAt = $issue->lastSyncedAt->modify('+6 hours');
    }

    /** @param list<string> $fingerprints */
    private function existing(string $repositoryId, string $fingerprint, array $fingerprints): ?IssueTracking
    {
        $issues = $this->em->getRepository(IssueTracking::class)->findBy(['repository' => $repositoryId], ['createdAt' => 'ASC']);
        $retry = null;
        foreach ($issues as $issue) {
            if ($issue->fingerprint === $fingerprint && 'failed' === $issue->status) {
                $retry = $issue;
                continue;
            }
            if ($issue->fingerprint === $fingerprint || (!in_array($issue->status, ['closed', 'failed'], true) && [] !== array_intersect($issue->fingerprints, $fingerprints))) {
                return $issue;
            }
        }

        return $retry;
    }

    private function detail(Finding $finding, string $key): string
    {
        $value = $finding->details[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : 'À vérifier';
    }
}
