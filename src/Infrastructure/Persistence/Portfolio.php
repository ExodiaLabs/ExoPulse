<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Service\ConfigurationStore;
use App\Domain\Model\Finding;
use App\Domain\Model\Repository;
use App\Domain\Model\Scan;
use Doctrine\ORM\EntityManagerInterface;

final readonly class Portfolio
{
    public function __construct(private EntityManagerInterface $em, private ?ConfigurationStore $configuration = null)
    {
    }

    /** @return array{repositories: list<Repository>, total: int, page: int, pages: int} */
    public function repositories(string $q = '', string $status = '', string $sort = 'name', int $page = 1, string $connection = ''): array
    {
        $qb = $this->em->createQueryBuilder()->select('r', 'c')->from(Repository::class, 'r')->join('r.connection', 'c');
        if ('' !== $q) {
            $qb->andWhere('LOWER(r.name) LIKE :q')->setParameter('q', '%'.mb_strtolower(mb_substr($q, 0, 150)).'%');
        }
        if ('' !== $connection) {
            $qb->andWhere('c.id = :connection')->setParameter('connection', $connection);
        }
        $conditions = $this->healthConditions() + ['archived' => 'r.archived = true', 'inaccessible' => 'r.accessible = false'];
        if (isset($conditions[$status])) {
            $qb->andWhere($conditions[$status]);
            if (str_contains($conditions[$status], ':cutoff')) {
                $qb->setParameter('cutoff', $this->cutoff());
            }
        }
        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        $orders = ['name' => ['r.name', 'ASC'], 'score' => ['r.latestScore', 'ASC'], 'recent' => ['r.lastScanAt', 'DESC']];
        [$field, $direction] = $orders[$sort] ?? $orders['name'];
        /** @var list<Repository> $repositories */
        $repositories = $qb->orderBy($field, $direction)->addOrderBy('r.id')->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();

        return compact('repositories', 'total', 'page', 'pages');
    }

    /** @return array{findings: list<Finding>, total: int, page: int, pages: int} */
    public function findings(string $q = '', string $severity = '', string $type = '', int $page = 1): array
    {
        $qb = $this->em->createQueryBuilder()->select('f', 'r', 'c')->from(Finding::class, 'f')->join('f.repository', 'r')->join('r.connection', 'c')->where('f.resolvedAt IS NULL');
        if ('' !== $q) {
            $qb->andWhere('LOWER(f.title) LIKE :q OR LOWER(r.name) LIKE :q')->setParameter('q', '%'.mb_strtolower(mb_substr($q, 0, 150)).'%');
        }
        foreach (['severity' => $severity, 'type' => $type] as $field => $value) {
            if ('' !== $value) {
                $qb->andWhere('f.'.$field.' = :'.$field)->setParameter($field, $value);
            }
        }
        $total = (int) (clone $qb)->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        /** @var list<Finding> $findings */
        $findings = $qb->orderBy('f.firstSeenAt')->addOrderBy('f.id')->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();

        return compact('findings', 'total', 'page', 'pages');
    }

    /** @return array{findings: list<Finding>, total: int, page: int, pages: int} */
    public function applicationFindings(Repository $repository, int $page = 1): array
    {
        $qb = $this->em->createQueryBuilder()->select('f')->from(Finding::class, 'f')
            ->where('f.repository = :repository')->andWhere('f.resolvedAt IS NULL')->setParameter('repository', $repository);
        $total = (int) (clone $qb)->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 20));
        $page = max(1, min($page, $pages));
        /** @var list<Finding> $findings */
        $findings = $qb->addSelect("CASE WHEN f.severity = 'critical' THEN 0 WHEN f.severity = 'warning' THEN 1 ELSE 2 END AS HIDDEN priority")
            ->orderBy('priority')->addOrderBy('f.firstSeenAt')->addOrderBy('f.id')
            ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();

        return compact('findings', 'total', 'page', 'pages');
    }

    /** @return array{scans: list<Scan>, total: int, page: int, pages: int} */
    public function scans(?Repository $repository = null, int $page = 1): array
    {
        $qb = $this->em->createQueryBuilder()->select('s', 'r', 'c')->from(Scan::class, 's')->join('s.repository', 'r')->join('r.connection', 'c');
        if (null !== $repository) {
            $qb->where('s.repository = :repository')->setParameter('repository', $repository);
        }
        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        /** @var list<Scan> $scans */
        $scans = $qb->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();

        return compact('scans', 'total', 'page', 'pages');
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $stats = ['total' => $this->em->getRepository(Repository::class)->count([])];
        $cutoff = $this->cutoff();
        foreach ($this->healthConditions() as $key => $condition) {
            $stats[$key] = (int) $this->em->createQueryBuilder()->select('COUNT(r.id)')->from(Repository::class, 'r')
                ->where($condition)->setParameter('cutoff', $cutoff)->getQuery()->getSingleScalarResult();
        }
        foreach (['unsupported' => ['end_of_support', 'eol', 'end_of_life', 'unsupported'], 'outdated' => ['outdated', 'major', 'minor', 'patch']] as $key => $types) {
            $stats[$key] = (int) $this->em->createQueryBuilder()->select('COUNT(f.id)')->from(Finding::class, 'f')->where('f.resolvedAt IS NULL')->andWhere('f.type IN (:types)')->setParameter('types', $types)->getQuery()->getSingleScalarResult();
        }

        return $stats;
    }

    /** @return list<array{date: string, score: int, count: int}> */
    public function trend(): array
    {
        /** @var list<Scan> $scans */
        $scans = $this->em->createQueryBuilder()->select('s')->from(Scan::class, 's')->where('s.score IS NOT NULL')->andWhere("s.status = 'completed'")->orderBy('s.createdAt', 'DESC')->setMaxResults(1000)->getQuery()->getResult();
        $days = [];
        foreach ($scans as $scan) {
            $day = $scan->createdAt->format('Y-m-d');
            $days[$day]['sum'] = ($days[$day]['sum'] ?? 0) + ($scan->score ?? 0);
            $days[$day]['count'] = ($days[$day]['count'] ?? 0) + 1;
        }
        ksort($days);
        $trend = [];
        foreach ($days as $day => $values) {
            $trend[] = ['date' => $day, 'score' => (int) round($values['sum'] / $values['count']), 'count' => $values['count']];
        }

        return $trend;
    }

    /** @return array<string, string> */
    private function healthConditions(): array
    {
        $fresh = 'r.lastScanAt >= :cutoff';
        $stale = '(r.lastScanAt IS NULL OR r.lastScanAt < :cutoff)';

        return [
            'healthy' => 'r.latestScore >= 80 AND '.$fresh,
            'attention' => 'r.latestScore >= 50 AND r.latestScore < 80 AND '.$fresh,
            'critical' => 'r.latestScore < 50 AND '.$fresh,
            'unknown' => '(r.latestScore IS NULL OR '.$stale.')',
            'stale' => $stale,
        ];
    }

    private function cutoff(): \DateTimeImmutable
    {
        $days = $this->configuration?->values()['staleDays'] ?? ConfigurationStore::DEFAULTS['staleDays'];

        return new \DateTimeImmutable('-'.max(1, $days).' days');
    }
}
