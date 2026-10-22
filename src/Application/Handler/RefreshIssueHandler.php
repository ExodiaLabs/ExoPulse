<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Message\RefreshIssue;
use App\Application\Service\IssueManager;
use App\Domain\Git\ProviderException;
use App\Domain\Model\IssueTracking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class RefreshIssueHandler
{
    public function __construct(private EntityManagerInterface $em, private IssueManager $issues)
    {
    }

    public function __invoke(RefreshIssue $message): void
    {
        $issue = $this->em->find(IssueTracking::class, $message->issueId);
        if (!$issue instanceof IssueTracking || '' === $issue->number || !$issue->repository->connection->active || (null !== $issue->lastSyncedAt && $issue->lastSyncedAt > new \DateTimeImmutable('-5 minutes'))) {
            return;
        }
        try {
            $this->issues->refresh($issue, 'scheduler', retryOnFailure: true);
        } catch (ProviderException $error) {
            if (null !== $error->status && $error->status < 500 && !in_array($error->status, [408, 429], true) && null === $error->retryAfter) {
                return;
            }
            throw new RecoverableMessageHandlingException('Vérification du statut temporairement indisponible.', retryDelay: max(60, min(3600, $error->retryAfter ?? 300)) * 1000, forceRetry: false);
        } catch (\DomainException) {
            return;
        } catch (\InvalidArgumentException) {
            throw new RecoverableMessageHandlingException('Une opération sur cette issue est déjà en cours.', retryDelay: 60000, forceRetry: false);
        }
    }
}
