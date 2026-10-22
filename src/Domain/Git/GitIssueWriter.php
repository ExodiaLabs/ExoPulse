<?php

declare(strict_types=1);

namespace App\Domain\Git;

interface GitIssueWriter
{
    public function createIssue(string $externalId, string $title, string $body): RemoteIssue;

    public function issue(string $externalId, string $number): RemoteIssue;
}
