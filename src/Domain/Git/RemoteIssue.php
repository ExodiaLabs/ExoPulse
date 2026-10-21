<?php

declare(strict_types=1);

namespace App\Domain\Git;

final readonly class RemoteIssue
{
    public function __construct(public string $number, public string $url, public string $state)
    {
    }
}
