<?php

declare(strict_types=1);

namespace App\Tests\Analysis;

use App\Domain\Analysis\AnalysisEngine;
use App\Domain\Analysis\ComposerParser;
use App\Domain\Analysis\HealthPolicy;
use App\Domain\Analysis\JavascriptLockParser;
use App\Domain\Analysis\JavascriptParser;
use App\Domain\Analysis\RuntimeDetector;
use App\Domain\Analysis\VersionComparison;
use App\Infrastructure\Registry\BundledLifecycle;
use App\Infrastructure\Registry\CatalogHttpClient;
use App\Infrastructure\Registry\LifecycleCatalog;
use App\Infrastructure\Registry\RegistryCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LegacyLifecycleTest extends TestCase
{
    private int $requests = 0;

    public function testLockedSymfonyFourHasSourcedOfflineEndOfSupportWithoutNetwork(): void
    {
        $result = $this->engine()->analyse($this->composer('symfony/framework-bundle', '^4.4', 'v4.4.51'), false);
        $symfony = array_values(array_filter($result->technologies, static fn (array $t): bool => 'Symfony' === $t['name']))[0];
        self::assertSame('v4.4.51', $symfony['resolved']);
        self::assertSame('2023-11-30', $symfony['eol'] ?? null);
        self::assertSame('month', $symfony['supportDatePrecision'] ?? null);
        self::assertSame('bundled', $symfony['supportOrigin'] ?? null);
        self::assertSame('known', $symfony['supportStatus'] ?? null);
        self::assertSame('https://symfony.com/releases/4.4.json', $symfony['supportSource'] ?? null);
        self::assertSame('end_of_support', $result->findings[0]['type']);
        self::assertSame('2026-10-22T00:00:00+00:00', $symfony['supportVerifiedAt'] ?? null);
        self::assertNull($result->dependencies[0]['latest']);
        self::assertSame(0, $this->requests);
        self::assertSame('4.4', $symfony['supportCycle'] ?? null);
        self::assertSame([], $result->errors);
        self::assertNull($result->score, 'Unknown registry checks must not become a healthy score.');
    }

    public function testClosedLegacyConstraintsAreEstimatesNeverResolvedVersions(): void
    {
        foreach (['^4.4', '4.4.*', '4.4.x', '~4.4.0'] as $constraint) {
            $result = $this->engine()->analyse($this->composer('symfony/framework-bundle', $constraint), false);
            $technology = $result->technologies[0];
            self::assertSame($constraint, $technology['declared']);
            self::assertNull($technology['resolved']);
            self::assertSame('estimated', $technology['supportStatus'] ?? null);
            self::assertSame(60, $technology['supportConfidence'] ?? null);
            self::assertSame(60, $result->findings[0]['confidence']);
            self::assertStringContainsString('estimation', $result->findings[0]['title']);
            self::assertNull($result->score);
        }
    }

    public function testAmbiguousConstraintsStayUnknown(): void
    {
        foreach (['>=7.1', '^7.1', '7.1 || 8.4', '*'] as $constraint) {
            $result = $this->engine()->analyse(['composer.json' => json_encode(['require' => ['php' => $constraint]], JSON_THROW_ON_ERROR)], false);
            self::assertSame('unknown', $result->technologies[0]['supportStatus'] ?? null);
            self::assertArrayNotHasKey('eol', $result->technologies[0]);
            self::assertSame('unknown', $result->findings[0]['type']);
            self::assertNull($result->score);
        }
    }

    public function testSymfonyFiveFourHasEndedMaintenanceButStillHasSecuritySupport(): void
    {
        $result = $this->engine()->analyse($this->composer('symfony/framework-bundle', '^5.4'), false);
        self::assertSame('2029-02-28', $result->technologies[0]['eol'] ?? null);
        self::assertSame('2024-11-30', $result->technologies[0]['endOfMaintenance'] ?? null);
        self::assertSame('estimated', $result->technologies[0]['supportStatus'] ?? null);
        self::assertNull($result->technologies[0]['resolved']);
        self::assertSame('end_of_maintenance', $result->findings[0]['type']);
        self::assertSame(0, $this->requests);
    }

    public function testSymfonyFiveFourSecurityExtensionCannotBeOverriddenByOnlineAggregator(): void
    {
        $calls = [];
        $http = new CatalogHttpClient(new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('{"result":{"releases":[{"name":"5.4","eolFrom":"2025-11-30"}]}}');
        }));
        $catalog = new LifecycleCatalog($http, new ArrayAdapter());
        $result = $catalog->lookup('Symfony', '5.4.54');
        self::assertSame('2029-02-28', $result['eol'] ?? null);
        self::assertSame('https://symfony.com/releases/5.4.json', $result['source']);
        self::assertSame('2026-10-22T00:00:00+00:00', $result['verifiedAt']);
        self::assertSame([], $calls);
    }

    public function testLaterUnsupportedSymfonyAndOddNodeReleasesHaveOfflineEvidence(): void
    {
        foreach (['5.0', '5.1', '5.2', '5.3', '6.0', '6.1', '6.2', '6.3', '7.0', '7.1', '7.2', '7.3'] as $cycle) {
            $result = $this->engine()->analyse($this->composer('symfony/framework-bundle', $cycle.'.*', $cycle.'.0'), false);
            self::assertSame('end_of_support', $result->findings[0]['type'], $cycle);
            self::assertSame('bundled', $result->technologies[0]['supportOrigin'] ?? null);
        }
        foreach (['23', '25'] as $version) {
            $result = $this->engine()->analyse(['.node-version' => $version], false);
            self::assertSame('end_of_support', $result->findings[0]['type']);
            self::assertNull($result->technologies[0]['resolved']);
        }
        self::assertSame(0, $this->requests);
    }

    public function testCaretCoveringMaintainedMinorNeverBecomesAnOldCycleFinding(): void
    {
        foreach (['^6.0', '^7.0'] as $constraint) {
            $result = $this->engine()->analyse($this->composer('symfony/framework-bundle', $constraint), false);
            self::assertSame('unknown', $result->technologies[0]['supportStatus'] ?? null);
            self::assertSame('unknown', $result->findings[0]['type']);
            self::assertNull($result->technologies[0]['resolved']);
        }
    }

    public function testComponentsAndLanguagesDoNotInventFrameworkOrInstalledRuntime(): void
    {
        $files = $this->composer('symfony/http-kernel', '^4.4', 'v4.4.51');
        $files['package.json'] = '{"devDependencies":{"typescript":"^3.0"}}';
        $result = $this->engine()->analyse($files, false);
        $technologies = array_column($result->technologies, null, 'name');
        self::assertArrayNotHasKey('Symfony', $technologies);
        self::assertSame('component', $technologies['Symfony (composants)']['kind'] ?? null);
        self::assertSame('2023-11-30', $technologies['Symfony (composants)']['eol'] ?? null);
        foreach (['PHP', 'JavaScript', 'TypeScript'] as $language) {
            self::assertSame('language', $technologies[$language]['kind'] ?? null);
            self::assertNull($technologies[$language]['resolved']);
        }
        self::assertSame('end_of_support', $result->findings[0]['type']);
        $result = $this->engine()->analyse($this->composer('symfony/monolog-bundle', '^3.0', '3.8.0'), false);
        self::assertNotContains('Symfony (composants)', array_column($result->technologies, 'name'));
    }

    public function testFrameworkAndCoreComponentsInSameCycleDoNotDuplicateSupport(): void
    {
        $files = $this->composer('symfony/framework-bundle', '^4.4', 'v4.4.51');
        $files['composer.lock'] = '{"packages":[{"name":"symfony/framework-bundle","version":"v4.4.51"},{"name":"symfony/http-kernel","version":"v4.4.49"},{"name":"symfony/console","version":"v3.4.49"}]}';
        $result = $this->engine()->analyse($files, false);
        $components = array_values(array_filter($result->technologies, static fn (array $t): bool => 'component' === ($t['kind'] ?? null)));
        self::assertCount(1, $components);
        self::assertSame('v3.4.49', $components[0]['resolved']);
        self::assertCount(2, array_filter($result->findings, static fn (array $f): bool => 'end_of_support' === $f['type']));
    }

    public function testOnlineOutageRetainsHistoricalReferenceWithExplicitProvenance(): void
    {
        $result = $this->engine(true)->analyse($this->composer('symfony/framework-bundle', '^4.4', 'v4.4.51'));
        self::assertSame('bundled', $result->technologies[0]['supportOrigin'] ?? null);
        self::assertSame('end_of_support', $result->findings[0]['type']);
        self::assertNotEmpty($result->errors);
        self::assertNull($result->score);
    }

    public function testOnlineAnalysisKeepsPrimaryCalendarAheadOfContradictoryAggregator(): void
    {
        $http = new CatalogHttpClient(new MockHttpClient(static function (string $method, string $url): MockResponse {
            return new MockResponse(str_contains($url, 'endoflife.date')
                ? '{"result":{"releases":[{"name":"4.4","eolFrom":"2023-11-21","eoasFrom":"2022-11-21"}]}}'
                : '{"packages":{"symfony/framework-bundle":[{"version":"v4.4.51"}]}}');
        }));
        $cache = new ArrayAdapter();
        $engine = new AnalysisEngine(new ComposerParser(), new JavascriptParser(new JavascriptLockParser()), new RuntimeDetector(), new RegistryCatalog($http, $cache), new LifecycleCatalog($http, $cache), new HealthPolicy(), new VersionComparison());
        $result = $engine->analyse($this->composer('symfony/framework-bundle', '^4.4', 'v4.4.51'));
        self::assertSame('bundled', $result->technologies[0]['supportOrigin'] ?? null);
        self::assertSame('month', $result->technologies[0]['supportDatePrecision'] ?? null);
        self::assertSame('2023-11-30', $result->technologies[0]['eol'] ?? null);
        self::assertSame('https://symfony.com/releases/4.4.json', $result->technologies[0]['supportSource'] ?? null);
    }

    public function testPrimaryHistoricalRuntimeReferencesAndMissingProducts(): void
    {
        $catalog = new BundledLifecycle();
        self::assertSame('2018-12-31', $catalog->lookup('PHP', '5.6.40')['eol'] ?? null);
        self::assertSame('2022-11-28', $catalog->lookup('PHP', '7.4')['eol'] ?? null);
        self::assertSame('2023-09-11', $catalog->lookup('Node.js', '16')['eol'] ?? null);
        self::assertSame('2023-12-31', $catalog->lookup('Vue', '2.7.16')['eol'] ?? null);
        self::assertNull($catalog->lookup('React', '16.14.0'));
        self::assertNull($catalog->lookup('PHP', '99.0'));
        $result = $this->engine()->analyse(['.php-version' => '7.4'], false);
        self::assertStringContainsString('runtime déployé à vérifier', $result->findings[0]['title']);
        self::assertNull($result->technologies[0]['resolved']);
    }

    /** @return array<string, string> */
    private function composer(string $package, string $constraint, ?string $resolved = null): array
    {
        $files = ['composer.json' => json_encode(['require' => [$package => $constraint]], JSON_THROW_ON_ERROR)];
        if (null !== $resolved) {
            $files['composer.lock'] = json_encode(['packages' => [['name' => $package, 'version' => $resolved]]], JSON_THROW_ON_ERROR);
        }

        return $files;
    }

    private function engine(bool $outage = false): AnalysisEngine
    {
        $http = new CatalogHttpClient(new MockHttpClient(function () use ($outage): MockResponse {
            ++$this->requests;
            if (!$outage) {
                self::fail('Offline analysis must perform zero network requests.');
            }

            return new MockResponse('{}', ['http_code' => 503]);
        }));
        $cache = new ArrayAdapter();

        return new AnalysisEngine(new ComposerParser(), new JavascriptParser(new JavascriptLockParser()), new RuntimeDetector(), new RegistryCatalog($http, $cache), new LifecycleCatalog($http, $cache), new HealthPolicy(), new VersionComparison());
    }
}
