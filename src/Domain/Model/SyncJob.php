<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'sync_job')]
#[ORM\Index(name: 'sync_status_created', columns: ['status', 'created_at'])]
class SyncJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: ProviderConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProviderConnection $connection;

    #[ORM\Column(type: 'string')]
    public string $status = 'queued';

    #[ORM\Column(type: 'integer')]
    public int $processed = 0;

    #[ORM\Column(type: 'integer')]
    public int $failed = 0;

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
