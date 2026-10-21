<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testExternalIdentifiersAreScopedToTheirConnection(): void
    {
        foreach (['GitHub public', 'GitHub entreprise'] as $name) {
            $connection = new ProviderConnection();
            $connection->name = $name;
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = '42';
            $repository->name = $name.'/application';
            $this->em->persist($connection);
            $this->em->persist($repository);
        }
        $this->em->flush();
        self::assertSame(2, $this->em->getRepository(Repository::class)->count(['externalId' => '42']));
    }

    public function testDatabaseRejectsDuplicateExternalIdentity(): void
    {
        $connection = new ProviderConnection();
        $this->em->persist($connection);
        for ($i = 0; $i < 2; ++$i) {
            $repository = new Repository();
            $repository->connection = $connection;
            $repository->externalId = 'same-external-id';
            $this->em->persist($repository);
        }
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
