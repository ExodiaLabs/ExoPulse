<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Message\AnalyzeRepository;
use App\Application\Service\ScanProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyzeRepositoryHandler
{
    public function __construct(private ScanProcessor $processor)
    {
    }

    public function __invoke(AnalyzeRepository $message): void
    {
        $this->processor->process($message->scanId);
    }
}
