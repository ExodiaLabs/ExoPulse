<?php

declare(strict_types=1);

namespace App\Domain\Git;

interface GitProvider extends GitReader, GitIssueWriter
{
}
