<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'repository')]
#[ORM\UniqueConstraint(name: 'repository_external_identity', columns: ['connection_id', 'external_id'])]
#[ORM\Index(name: 'repository_health', columns: ['latest_score'])]
class Repository
{
    use TracksChanges;
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: ProviderConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProviderConnection $connection;

    #[ORM\Column(type: 'string')]
    public string $externalId = '';

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'text')]
    public string $url = '';

    #[ORM\Column(type: 'string')]
    public string $defaultBranch = 'main';

    #[ORM\Column(type: 'boolean')]
    public bool $archived = false;

    #[ORM\Column(type: 'boolean')]
    public bool $accessible = true;

    #[ORM\Column(type: 'boolean')]
    public bool $monitored = true;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $lastCommit = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastScanAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $latestScore = null;

    #[ORM\Column(type: 'integer')]
    public int $confidence = 0;

    #[ORM\Column(type: 'integer')]
    public int $coverage = 0;

    #[ORM\ManyToOne(targetEntity: Scan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Scan $latestScan = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
