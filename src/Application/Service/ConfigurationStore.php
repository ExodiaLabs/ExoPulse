<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Configuration;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConfigurationStore
{
    private const ID = '00000000-0000-7000-8000-000000000001';
    public const DEFAULTS = ['frequencyHours' => 24, 'staleDays' => 7, 'retentionDays' => 365, 'end_of_support' => 40, 'end_of_maintenance' => 10, 'major' => 10, 'minor' => 3, 'patch' => 1];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array<string, int> */
    public function values(): array
    {
        $configuration = $this->em->find(Configuration::class, self::ID);

        return array_replace(self::DEFAULTS, $configuration->values ?? []);
    }

    /** @param array<string, int> $values */
    public function save(array $values): void
    {
        $configuration = $this->em->find(Configuration::class, self::ID) ?? new Configuration();
        $configuration->id = self::ID;
        $configuration->values = array_intersect_key($values, self::DEFAULTS);
        $this->em->persist($configuration);
    }
}
