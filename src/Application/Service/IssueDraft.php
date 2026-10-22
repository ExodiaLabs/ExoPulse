<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\Repository;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class IssueDraft
{
    /** @param list<Finding> $findings */
    public function __construct(public Repository $repository, public array $findings, public string $title, public string $body, public string $fingerprint, public ?IssueTracking $existingIssue = null)
    {
    }
}
