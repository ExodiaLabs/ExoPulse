<?php

declare(strict_types=1);

namespace App\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'provider_connection')]
class ProviderConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'string')]
    public string $provider = 'github';

    #[ORM\Column(type: 'string')]
    public string $baseUrl = 'https://github.com';

    #[ORM\Column(type: 'text')]
    public string $encryptedToken = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $organizations = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $includedRepositories = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $excludedRepositories = [];

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $branch = null;

    #[ORM\Column(type: 'integer')]
    public int $frequencyHours = 24;

    #[ORM\Column(type: 'boolean')]
    public bool $active = true;

    #[ORM\Column(type: 'string')]
    public string $status = 'untested';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastTestedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $nextSyncAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }
}
