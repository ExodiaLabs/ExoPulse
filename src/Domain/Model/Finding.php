<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'finding')]
#[ORM\UniqueConstraint(name: 'finding_identity', columns: ['repository_id', 'fingerprint'])]
#[ORM\Index(name: 'finding_severity', columns: ['severity', 'resolved_at'])]
class Finding
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

    #[ORM\Column(type: 'string')]
    public string $type = 'unknown';

    #[ORM\Column(type: 'string')]
    public string $severity = 'info';

    #[ORM\Column(type: 'text')]
    public string $title = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $details = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }
}
