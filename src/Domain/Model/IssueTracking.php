<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'issue_tracking')]
#[ORM\UniqueConstraint(name: 'issue_deduplication', columns: ['repository_id', 'fingerprint'])]
class IssueTracking
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Repository::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Repository $repository;

    #[ORM\Column(type: 'string')]
    public string $fingerprint = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $fingerprints = [];

    #[ORM\Column(type: 'string')]
    public string $number = '';

    #[ORM\Column(type: 'text')]
    public string $url = '';

    #[ORM\Column(type: 'string')]
    public string $status = 'creating';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastSyncedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }
}
