<?php

declare(strict_types=1);

namespace App\Application\Message;

final readonly class SynchronizeConnection implements PortfolioMessage
{
    public function __construct(public string $jobId)
    {
    }
}
