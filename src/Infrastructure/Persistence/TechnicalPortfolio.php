<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Service\ConfigurationStore;
use App\Domain\Model\Repository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class TechnicalPortfolio
{
    public function __construct(private EntityManagerInterface $em, private ?ConfigurationStore $configuration = null)
    {
    }

    /** @param array<string,string> $filters
     * @return array{repositories:list<Repository>,total:int,page:int,pages:int,diagnostics:array<string,array<string,mixed>>}
     */
    public function repositories(array $filters = [], int $page = 1): array
    {
        $days = $this->configuration?->values()['staleDays'] ?? ConfigurationStore::DEFAULTS['staleDays'];
        [$where, $parameters] = TechnicalPortfolioQuery::filters($filters, (new \DateTimeImmutable('-'.max(1, $days).' days'))->format('Y-m-d H:i:s'));
        $sql = TechnicalPortfolioQuery::cte();
        $connection = $this->em->getConnection();
        $total = self::number($connection->fetchOne($sql.' SELECT count(*) FROM classified c WHERE '.$where, $parameters));
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        $risk = "CASE c.risk WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
        $orders = ['name' => 'c.name ASC', 'score' => 'c.latest_score ASC NULLS LAST', 'recent' => 'c.last_scan_at DESC NULLS LAST', 'criticality' => $risk, 'oldest' => 'c.oldest_finding_at ASC NULLS LAST', 'urgency' => $risk.', c.oldest_finding_at ASC NULLS LAST'];
        $order = $orders[$filters['sort'] ?? 'name'] ?? $orders['name'];
        // Limits are bounded integers computed locally, never interpolated user strings.
        $rows = $connection->fetchAllAssociative($sql.' SELECT c.* FROM classified c WHERE '.$where.' ORDER BY '.$order.', c.id LIMIT 25 OFFSET '.(($page - 1) * 25), $parameters);
        $diagnostics = $this->diagnostics($rows);
        $ids = array_keys($diagnostics);
        $repositories = [];
        if ([] !== $ids) {
            $entities = $this->em->createQueryBuilder()->select('r', 'connection', 'scan')->from(Repository::class, 'r')->join('r.connection', 'connection')->leftJoin('r.latestScan', 'scan')->where('r.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
            if (!is_array($entities)) {
                throw new \LogicException('Unexpected repository result.');
            }
            $byId = [];
            foreach ($entities as $entity) {
                if ($entity instanceof Repository) {
                    $byId[$entity->id] = $entity;
                }
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $repositories[] = $byId[$id];
                }
            }
        }

        return compact('repositories', 'total', 'page', 'pages', 'diagnostics');
    }

    /** @param list<string> $repositoryIds
     * @return array<string,array<string,mixed>>
     */
    public function diagnosticsFor(array $repositoryIds): array
    {
        $ids = array_values(array_unique(array_filter($repositoryIds, Uuid::isValid(...))));
        $result = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->em->getConnection()->fetchAllAssociative(TechnicalPortfolioQuery::cte().' SELECT c.* FROM classified c WHERE c.id IN (:ids)', ['ids' => $chunk], ['ids' => ArrayParameterType::STRING]);
            $result += $this->diagnostics($rows);
        }

        return $result;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $row = $this->em->getConnection()->fetchAssociative(TechnicalPortfolioQuery::cte().<<<'SQL'
 SELECT count(*) AS total,
 count(*) FILTER (WHERE legacy = 'confirmed') AS "legacyConfirmed",
 count(*) FILTER (WHERE legacy = 'suspected') AS "legacySuspected",
 count(*) FILTER (WHERE eol_count > 0) AS unsupported,
 count(*) FILTER (WHERE maintenance_count > 0) AS aging,
 count(*) FILTER (WHERE outdated_count > 0) AS outdated,
 count(*) FILTER (WHERE risk = 'unknown') AS unknown
 FROM classified
SQL);
        $stats = [];
        foreach (false === $row ? [] : $row as $key => $value) {
            $stats[$key] = self::number($value);
        }

        return $stats;
    }

    /** @return list<array{name:string,projects:int,eolProjects:int}> */
    public function technologyInventory(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(TechnicalPortfolioQuery::cte().<<<'SQL'
 SELECT min(o.name) AS name, count(DISTINCT o.repository_id) AS projects,
 count(DISTINCT o.repository_id) FILTER (WHERE EXISTS (
   SELECT 1 FROM finding_rows f WHERE f.repository_id = o.repository_id
   AND lower(f.technology) = lower(o.name) AND f.confidence >= 50
   AND f.type IN ('end_of_support','eol','end_of_life','unsupported')
 )) AS eol_projects
 FROM observations o GROUP BY lower(o.name) ORDER BY lower(o.name)
SQL);
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['name' => self::text($row['name']), 'projects' => self::number($row['projects']), 'eolProjects' => self::number($row['eol_projects'])];
        }

        return $result;
    }

    /** @return array{technologies:list<string>,languages:list<string>,frameworks:list<string>} */
    public function facets(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(TechnicalPortfolioQuery::cte().' SELECT DISTINCT name, language, framework FROM observations ORDER BY name');
        $values = ['technologies' => [], 'languages' => [], 'frameworks' => []];
        foreach ($rows as $row) {
            foreach (['technologies' => 'name', 'languages' => 'language', 'frameworks' => 'framework'] as $key => $column) {
                $value = self::text($row[$column]);
                if ('' !== $value) {
                    $values[$key][mb_strtolower($value)] = $value;
                }
            }
        }
        $result = [];
        foreach ($values as $key => $items) {
            natcasesort($items);
            $result[$key] = array_values($items);
        }

        return ['technologies' => $result['technologies'], 'languages' => $result['languages'], 'frameworks' => $result['frameworks']];
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function diagnostics(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $risk = self::text($row['risk']);
            $legacy = self::text($row['legacy']);
            $oldest = self::date($row['oldest_finding_at']);
            $eol = self::date($row['oldest_eol']);
            $verified = self::date($row['last_verified_at']);
            $reasons = [];
            foreach (['critical_count' => 'Un framework est hors support avec une confiance élevée.', 'eol_count' => 'Une technologie est hors support ; les runtimes restent à vérifier sur le déploiement.', 'major_count' => 'Une mise à jour majeure est disponible ; sa compatibilité doit être vérifiée.', 'maintenance_count' => 'La maintenance active d’une technologie est terminée.', 'outdated_count' => 'Des dépendances disposent de versions plus récentes.'] as $column => $reason) {
                if (self::number($row[$column]) > 0) {
                    $reasons[] = $reason;
                }
            }
            $partial = !in_array($row['complete'], [true, 1, '1', 't'], true);
            if ($partial) {
                $reasons[] = 'L’analyse reste partielle ou insuffisamment vérifiée ; les risques inconnus ne sont pas assimilés à une absence de risque.';
            }
            $technologies = is_string($row['technologies']) ? json_decode($row['technologies'], true) : $row['technologies'];
            $technologies = is_array($technologies) ? array_values(array_filter($technologies, static fn (mixed $item): bool => is_array($item) && is_string($item['name'] ?? null))) : [];
            $result[self::text($row['id'])] = [
                'risk' => $risk, 'legacy' => $legacy,
                'riskLabel' => ['critical' => 'Critique', 'high' => 'Élevé', 'moderate' => 'Modéré', 'low' => 'Faible sur le périmètre vérifié', 'unknown' => 'Inconnu'][$risk] ?? 'Inconnu',
                'legacyLabel' => ['confirmed' => 'Legacy confirmé', 'suspected' => 'Legacy suspecté', 'none' => 'Aucun legacy confirmé', 'unknown' => 'Legacy indéterminé'][$legacy] ?? 'Legacy indéterminé',
                'eolCount' => self::number($row['eol_count']), 'maintenanceCount' => self::number($row['maintenance_count']), 'outdatedCount' => self::number($row['outdated_count']), 'majorCount' => self::number($row['major_count']),
                'oldestFindingAt' => $oldest?->format(DATE_ATOM), 'lastVerifiedAt' => $verified?->format(DATE_ATOM),
                'oldestFindingAgeDays' => self::age($oldest), 'eolDurationDays' => self::age($eol),
                'priorityReasons' => $reasons, 'partial' => $partial, 'technologies' => $technologies,
            ];
        }

        return $result;
    }

    private static function number(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \LogicException('Unexpected numeric database result.');
        }

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function age(?\DateTimeImmutable $date): ?int
    {
        return null === $date ? null : max(0, (int) $date->diff(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('%r%a'));
    }
}
