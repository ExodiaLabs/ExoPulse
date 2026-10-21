<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Message\SynchronizeConnection;
use App\Application\Service\SyncProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SynchronizeConnectionHandler
{
    public function __construct(private SyncProcessor $processor)
    {
    }

    public function __invoke(SynchronizeConnection $message): void
    {
        $this->processor->process($message->jobId);
    }
}
