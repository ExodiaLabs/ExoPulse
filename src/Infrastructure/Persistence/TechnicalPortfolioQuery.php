<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

final class TechnicalPortfolioQuery
{
    public static function cte(): string
    {
        return <<<'SQL'
WITH observations AS (
    SELECT r.id AS repository_id, t.value AS observation, t.value->>'name' AS name,
           CASE
             WHEN lower(t.value->>'name') = 'typescript' THEN 'TypeScript'
             WHEN lower(t.value->>'name') IN ('php', 'composer') OR lower(t.value->>'name') ~ '^symfony([ /-]|$)' THEN 'PHP'
             WHEN lower(t.value->>'name') IN ('javascript', 'node.js', 'node', 'react', 'vue', 'npm', 'pnpm', 'yarn') THEN 'JavaScript'
           END AS language,
           CASE
             WHEN lower(t.value->>'name') ~ '^symfony([ /-]|$)' THEN 'Symfony'
             WHEN lower(t.value->>'name') = 'react' THEN 'React'
             WHEN lower(t.value->>'name') = 'vue' THEN 'Vue'
           END AS framework
    FROM repository r JOIN scan s ON s.id = r.latest_scan_id
    CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(s.results::jsonb->'technologies') = 'array' THEN s.results::jsonb->'technologies' ELSE '[]'::jsonb END) t(value)
    WHERE jsonb_typeof(t.value) = 'object' AND jsonb_typeof(t.value->'name') = 'string' AND t.value->>'name' <> ''
) , observation_verification AS (
    SELECT repository_id, max(CASE WHEN value ~ '^\d{4}-\d{2}-\d{2}[T ]' AND pg_input_is_valid(value, 'timestamp with time zone') THEN value::timestamptz END) AS last_verified_at
    FROM observations CROSS JOIN LATERAL (VALUES (observation->>'supportVerifiedAt'), (observation->>'verifiedAt')) verification(value)
    GROUP BY repository_id
), finding_rows AS (
    SELECT f.repository_id, f.type, f.first_seen_at,
           f.details::jsonb->>'technology' AS technology,
           CASE WHEN f.details::jsonb->>'verifiedAt' ~ '^\d{4}-\d{2}-\d{2}[T ]' AND pg_input_is_valid(f.details::jsonb->>'verifiedAt', 'timestamp with time zone') THEN (f.details::jsonb->>'verifiedAt')::timestamptz END AS verified_at,
           CASE WHEN f.details::jsonb->>'eol' ~ '^\d{4}-\d{2}-\d{2}$' AND pg_input_is_valid(f.details::jsonb->>'eol', 'date') THEN (f.details::jsonb->>'eol')::date END AS eol,
           CASE WHEN f.details::jsonb->>'confidence' ~ '^(100|[0-9]{1,2})$' THEN (f.details::jsonb->>'confidence')::integer ELSE 0 END AS confidence,
           (lower(f.details::jsonb->>'technology') IN ('react', 'vue') OR lower(f.details::jsonb->>'technology') ~ '^symfony([ /-]|$)') AS framework
    FROM finding f WHERE f.resolved_at IS NULL
), evidence AS (
    SELECT repository_id,
      count(*) FILTER (WHERE type IN ('end_of_support','eol','end_of_life','unsupported') AND confidence >= 50) AS eol_count,
      count(*) FILTER (WHERE type IN ('end_of_support','eol','end_of_life','unsupported') AND confidence >= 80 AND framework) AS critical_count,
      count(*) FILTER (WHERE type IN ('end_of_support','eol','end_of_life','unsupported') AND confidence >= 100 AND framework) AS confirmed_count,
      count(*) FILTER (WHERE type IN ('end_of_maintenance','maintenance') AND confidence >= 50) AS maintenance_count,
      count(*) FILTER (WHERE type IN ('end_of_maintenance','maintenance') AND confidence >= 80 AND framework) AS high_maintenance_count,
      count(*) FILTER (WHERE type IN ('outdated','major','minor','patch') AND confidence >= 50) AS outdated_count,
      count(*) FILTER (WHERE type = 'major' AND confidence >= 50) AS major_count,
      count(*) FILTER (WHERE type = 'unknown' OR confidence < 50) AS unknown_count,
      min(first_seen_at) FILTER (WHERE type <> 'unknown' AND confidence >= 50) AS oldest_finding_at,
      max(verified_at) FILTER (WHERE confidence >= 50) AS last_verified_at,
      min(eol) FILTER (WHERE type IN ('end_of_support','eol','end_of_life','unsupported') AND confidence >= 50) AS oldest_eol
    FROM finding_rows GROUP BY repository_id
), base AS (
    SELECT r.id, r.name, r.latest_score, r.last_scan_at, r.archived, r.accessible, r.connection_id,
      s.status AS scan_status, s.results::jsonb->'technologies' AS technologies,
      COALESCE(e.eol_count,0) AS eol_count, COALESCE(e.critical_count,0) AS critical_count,
      COALESCE(e.confirmed_count,0) AS confirmed_count, COALESCE(e.maintenance_count,0) AS maintenance_count,
      COALESCE(e.high_maintenance_count,0) AS high_maintenance_count, COALESCE(e.outdated_count,0) AS outdated_count,
      COALESCE(e.major_count,0) AS major_count, COALESCE(e.unknown_count,0) AS unknown_count,
      e.oldest_finding_at, GREATEST(e.last_verified_at, v.last_verified_at) AS last_verified_at, e.oldest_eol,
      COALESCE(s.status = 'completed' AND s.coverage >= 100 AND s.confidence >= 100
        AND EXISTS(SELECT 1 FROM observations o WHERE o.repository_id = r.id)
        AND (s.results::jsonb->'errors' IS NULL OR s.results::jsonb->'errors' = '[]'::jsonb)
        AND COALESCE(e.unknown_count,0) = 0, false) AS complete
    FROM repository r LEFT JOIN scan s ON s.id = r.latest_scan_id LEFT JOIN evidence e ON e.repository_id = r.id LEFT JOIN observation_verification v ON v.repository_id = r.id
), classified AS (
    SELECT base.*,
      CASE WHEN critical_count > 0 THEN 'critical'
           WHEN eol_count > 0 OR major_count > 0 OR high_maintenance_count > 0 THEN 'high'
           WHEN maintenance_count > 0 THEN 'moderate'
           WHEN complete THEN 'low' ELSE 'unknown' END AS risk,
      CASE WHEN confirmed_count > 0 THEN 'confirmed' WHEN eol_count > 0 THEN 'suspected'
           WHEN complete THEN 'none' ELSE 'unknown' END AS legacy
    FROM base
)
SQL;
    }

    /** @param array<string,string> $filters
     * @return array{string,array<string,string>}
     */
    public static function filters(array $filters, string $cutoff): array
    {
        $where = ['1=1'];
        $parameters = [];
        if ('' !== ($filters['q'] ?? '')) {
            $where[] = 'lower(c.name) LIKE :q';
            $parameters['q'] = '%'.mb_strtolower(mb_substr($filters['q'], 0, 150)).'%';
        }
        if ('' !== ($filters['connection'] ?? '')) {
            // Compare as text so malformed filter input cannot trigger a UUID cast.
            $where[] = 'c.connection_id::text = :connection';
            $parameters['connection'] = $filters['connection'];
        }
        $health = [
            'healthy' => 'c.latest_score >= 80 AND c.last_scan_at >= :cutoff',
            'attention' => 'c.latest_score >= 50 AND c.latest_score < 80 AND c.last_scan_at >= :cutoff',
            'critical' => 'c.latest_score < 50 AND c.last_scan_at >= :cutoff',
            'unknown' => '(c.latest_score IS NULL OR c.last_scan_at IS NULL OR c.last_scan_at < :cutoff)',
            'stale' => '(c.last_scan_at IS NULL OR c.last_scan_at < :cutoff)',
            'archived' => 'c.archived = true', 'inaccessible' => 'c.accessible = false',
        ];
        if (isset($health[$filters['status'] ?? ''])) {
            $condition = $health[$filters['status']];
            $where[] = $condition;
            if (str_contains($condition, ':cutoff')) {
                $parameters['cutoff'] = $cutoff;
            }
        }
        foreach (['risk' => ['critical', 'high', 'moderate', 'low', 'unknown'], 'legacy' => ['confirmed', 'suspected', 'none', 'unknown']] as $key => $allowed) {
            if (in_array($filters[$key] ?? '', $allowed, true)) {
                $where[] = 'c.'.$key.' = :'.$key;
                $parameters[$key] = $filters[$key];
            }
        }
        $observation = ['o.repository_id = c.id'];
        foreach (['technology' => 'name', 'language' => 'language', 'framework' => 'framework'] as $filter => $column) {
            if ('' !== ($filters[$filter] ?? '')) {
                $observation[] = 'lower(o.'.$column.') = :'.$filter;
                $parameters[$filter] = mb_strtolower(mb_substr($filters[$filter], 0, 255));
            }
        }
        if ('' !== ($filters['version'] ?? '')) {
            $version = mb_substr($filters['version'], 0, 80);
            $observation[] = "(COALESCE(o.observation->>'resolved','') ~ :version OR COALESCE(o.observation->>'declared','') ~ :version)";
            $parameters['version'] = '(^|[^0-9.])v?'.preg_quote($version, '~').'([.]|[^0-9.]|$)';
        }
        if (count($observation) > 1) {
            $where[] = 'EXISTS(SELECT 1 FROM observations o WHERE '.implode(' AND ', $observation).')';
        }

        return [implode(' AND ', $where), $parameters];
    }
}
