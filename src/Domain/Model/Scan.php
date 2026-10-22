<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'scan')]
#[ORM\Index(name: 'scan_repository_created', columns: ['repository_id', 'created_at'])]
class Scan
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
    public string $status = 'queued';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $commitHash = null;

    #[ORM\Column(type: 'string')]
    public string $branch = '';

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $score = null;

    #[ORM\Column(type: 'integer')]
    public int $confidence = 0;

    #[ORM\Column(type: 'integer')]
    public int $coverage = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $results = [];

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }
}
