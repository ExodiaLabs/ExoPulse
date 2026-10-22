<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Message\AnalyzeRepository;
use App\Application\Message\SynchronizeConnection;
use App\Domain\Model\Scan;
use App\Domain\Model\SyncJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -100)]
final readonly class JobFailureListener
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (!$this->em->isOpen()) {
            return;
        }
        $message = $event->getEnvelope()->getMessage();
        $job = match (true) {
            $message instanceof AnalyzeRepository => $this->em->find(Scan::class, $message->scanId),
            $message instanceof SynchronizeConnection => $this->em->find(SyncJob::class, $message->jobId),
            default => null,
        };
        if (null === $job) {
            return;
        }
        $job->status = $event->willRetry() ? 'retrying' : 'failed';
        $job->finishedAt = $event->willRetry() ? null : new \DateTimeImmutable();
        $job->lastError ??= 'Le traitement a échoué ; consultez la file des échecs.';
        $this->em->flush();
    }
}
