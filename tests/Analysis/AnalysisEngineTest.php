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
use App\Infrastructure\Registry\CatalogHttpClient;
use App\Infrastructure\Registry\LifecycleCatalog;
use App\Infrastructure\Registry\RegistryCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnalysisEngineTest extends TestCase
{
    public function testLegacyComposerSeparatesDeclaredAndInstalledAndNeverRequiresModernRuntime(): void
    {
        $result = $this->engine()->analyse([
            'composer.json' => '{"require":{"php":">=5.3","symfony/framework-bundle":"~2.8"},"require-dev":{"phpunit/phpunit":"~4.8"}}',
            'composer.lock' => '{"packages":[{"name":"symfony/framework-bundle","version":"v2.8.52"},{"name":"symfony/http-kernel","version":"v2.8.52"}],"packages-dev":[{"name":"phpunit/phpunit","version":"4.8.36"}]}',
        ], false);
        self::assertCount(3, $result->dependencies);
        self::assertSame('~2.8', $result->dependencies[0]['declared']);
        self::assertSame('v2.8.52', $result->dependencies[0]['resolved']);
        $byName = array_column($result->dependencies, null, 'name');
        self::assertFalse($byName['symfony/http-kernel']['direct']);
        self::assertSame('dev', $byName['phpunit/phpunit']['scope']);
        self::assertNull($result->score);
        self::assertContains('Symfony', array_column($result->technologies, 'name'));
    }

    public function testWithoutLockDeclaredVersionNeverBecomesInstalled(): void
    {
        $result = $this->engine()->analyse(['package.json' => '{"dependencies":{"react":"^16.0.0"}}'], false);
        self::assertNull($result->dependencies[0]['resolved']);
        self::assertSame(0, $result->coverage);
        self::assertNull($result->score);
    }

    public function testMalformedLockPreservesDeclaredInventoryAndReportsPartialState(): void
    {
        $result = $this->engine()->analyse(['composer.json' => '{"require":{"vendor/package":"^1.0"}}', 'composer.lock' => '{invalid'], false);
        self::assertCount(1, $result->dependencies);
        self::assertNotEmpty($result->errors);
        self::assertNull($result->dependencies[0]['resolved']);
        self::assertNull($result->score);
    }

    public function testNpmNestedPackagesDoNotBecomeDirectDependencies(): void
    {
        $result = $this->engine()->analyse([
            'package.json' => '{"dependencies":{"react":"^16.0.0"}}',
            'package-lock.json' => '{"lockfileVersion":3,"packages":{"":{},"node_modules/react":{"version":"16.14.0"},"node_modules/a/node_modules/react":{"version":"15.0.0"}}}',
        ], false);
        self::assertCount(2, $result->dependencies);
        self::assertTrue($result->dependencies[0]['direct']);
        self::assertFalse($result->dependencies[1]['direct']);
    }

    public function testNpmShrinkwrapHasPriorityOverPackageLock(): void
    {
        $result = $this->engine()->analyse([
            'package.json' => '{"dependencies":{"example":"^1.0.0"}}',
            'npm-shrinkwrap.json' => '{"lockfileVersion":3,"packages":{"node_modules/example":{"version":"1.0.1"}}}',
            'package-lock.json' => '{"lockfileVersion":3,"packages":{"node_modules/example":{"version":"1.0.0"}}}',
        ], false);
        self::assertCount(1, $result->dependencies);
        self::assertSame('1.0.1', $result->dependencies[0]['resolved']);
        self::assertSame('npm-shrinkwrap.json', $result->dependencies[0]['source']);
    }

    public function testNpmVersionOneRecursivelyRetainsTransitivePackages(): void
    {
        $parser = new JavascriptLockParser();
        $entries = $parser->parse('package-lock.json', '{"lockfileVersion":1,"dependencies":{"a":{"version":"1.0.0","dependencies":{"b":{"version":"2.0.0"}}}}}', ['a' => '^1']);
        self::assertCount(2, $entries);
        self::assertTrue($entries[0]['direct']);
        self::assertFalse($entries[1]['direct']);
    }

    public function testPnpmNineRootImporterAndPeerSuffixResolveWithoutScripts(): void
    {
        $parser = new JavascriptLockParser();
        $entries = $parser->parse('pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      vue:\n        specifier: ^3.0.0\n        version: 3.4.0(typescript@5.0.0)\npackages:\n  vue@3.4.0:\n    resolution: {integrity: fake}\n", ['vue' => '^3.0.0']);
        self::assertSame([['name' => 'vue', 'version' => '3.4.0', 'direct' => true]], $entries);
    }

    public function testPnpmFiveLegacyPackagesAndDependenciesResolve(): void
    {
        $entries = (new JavascriptLockParser())->parse('pnpm-lock.yaml', "lockfileVersion: 5.4\ndependencies:\n  react: 16.14.0\npackages:\n  /react/16.14.0:\n    resolution: {integrity: fake}\n  /scheduler/0.19.1:\n    resolution: {integrity: fake}\n", ['react' => '^16.0.0']);
        self::assertCount(2, $entries);
        self::assertTrue($entries[0]['direct']);
        self::assertSame('scheduler', $entries[1]['name']);
    }

    public function testYarnClassicAndBerryReadRootSelectors(): void
    {
        $parser = new JavascriptLockParser();
        $classic = $parser->parse('yarn.lock', "# yarn lockfile v1\nreact@^16.0.0:\n  version \"16.14.0\"\n", ['react' => '^16.0.0']);
        $berry = $parser->parse('yarn.lock', "__metadata:\n  version: 8\n\"react@npm:^16.0.0\":\n  version: 16.14.0\n  resolution: \"react@npm:16.14.0\"\n", ['react' => '^16.0.0']);
        self::assertSame($classic, $berry);
        self::assertTrue($classic[0]['direct']);
    }

    public function testUnrecognisedLockFormatIsExplicitlyPartial(): void
    {
        $result = $this->engine()->analyse(['package.json' => '{"dependencies":{"vue":"^2"}}', 'pnpm-lock.yaml' => 'lockfileVersion: 999'], false);
        self::assertNotEmpty($result->errors);
        self::assertNull($result->dependencies[0]['resolved']);
    }

    public function testRuntimeSourcesAreIntentionsAndDynamicVersionsRemainUnknown(): void
    {
        $result = $this->engine()->analyse(['.nvmrc' => 'lts/*', '.php-version' => '5.6', 'Dockerfile' => "FROM php:7.4-fpm-alpine\nFROM node:20-alpine\n"], false);
        self::assertCount(4, $result->technologies);
        self::assertSame('lts/*', $result->technologies[0]['declared']);
        self::assertSame([null, null, null, null], array_column($result->technologies, 'resolved'));
        self::assertNull($result->score);
    }

    public function testUnavailableCatalogCannotProduceHealthyScoreOrVulnerability(): void
    {
        $result = $this->engine(status: 503)->analyse($this->npmFiles());
        self::assertNull($result->score);
        self::assertNotEmpty($result->errors);
        self::assertSame(0, $result->confidence);
        self::assertNotContains('vulnerability', array_column($result->findings, 'type'));
    }

    public function testMajorAndPatchComparisonRetainsLatestCompatible(): void
    {
        $versions = new VersionComparison();
        self::assertSame(['latest' => '2.0.0', 'compatible' => '1.2.8', 'type' => 'major'], $versions->compare(['1.2.8', '2.0.0', '3.0.0-beta.1'], '^1.2', '1.2.3'));
        self::assertSame('patch', $versions->compare(['1.2.8'], '^1.2', '1.2.3')['type']);
        self::assertNull($versions->compare(['2.0.0'], 'workspace:*', null)['compatible']);
    }

    public function testCatalogEnrichmentProducesSourcesDatesAndConfigurableScore(): void
    {
        $engine = $this->engine(['versions' => ['1.0.0' => [], '1.0.1' => [], '2.0.0' => []]]);
        $result = $engine->analyse($this->npmFiles());
        self::assertSame('2.0.0', $result->dependencies[0]['latest']);
        self::assertSame('1.0.1', $result->dependencies[0]['latestCompatible']);
        self::assertNotNull($result->dependencies[0]['verifiedAt']);
        self::assertSame(90, $result->score);
        self::assertStringStartsWith('https://registry.npmjs.org/', $result->findings[0]['source']);
        self::assertSame(80, $engine->analyse($this->npmFiles(), true, ['major' => 20])->score);
    }

    public function testMissingLifecycleDateNeverBecomesEndOfSupportFinding(): void
    {
        $result = $this->engine(['result' => ['releases' => [['name' => '99.1', 'eolFrom' => null, 'eoasFrom' => null]]]])->analyse(['.php-version' => '99.1']);
        self::assertSame('unknown', $result->findings[0]['type']);
        self::assertNull($result->score);
    }

    public function testEndOfLifeUsesExplicitDateAndDoesNotClaimActualRuntime(): void
    {
        $result = $this->engine(['result' => ['releases' => [['name' => '7.4', 'eolFrom' => '2022-11-28', 'eoasFrom' => '2021-11-28', 'isMaintained' => false]]]])->analyse(['.php-version' => '7.4']);
        self::assertSame('end_of_support', $result->findings[0]['type']);
        self::assertSame('2022-11-28', $result->findings[0]['eol']);
        self::assertStringContainsString('runtime déployé à vérifier', $result->findings[0]['title']);
        self::assertSame(60, $result->score);
    }

    public function testSupportedSeriesStillExposesItsFutureSupportDates(): void
    {
        $result = $this->engine(['result' => ['releases' => [['name' => '8.4', 'eolFrom' => '2099-12-31', 'eoasFrom' => '2098-12-31', 'isMaintained' => true, 'latest' => ['name' => '8.4.26']]]]])->analyse(['.php-version' => '8.4']);
        self::assertSame('2099-12-31', $result->technologies[0]['eol'] ?? null);
        self::assertSame('2098-12-31', $result->technologies[0]['endOfMaintenance'] ?? null);
        self::assertNotNull($result->technologies[0]['supportVerifiedAt'] ?? null);
        self::assertSame([], $result->findings);
        self::assertSame(100, $result->score);
    }

    public function testReactHasNoInventedSupportCalendar(): void
    {
        $catalog = new LifecycleCatalog(new CatalogHttpClient(new MockHttpClient(static fn (): never => throw new \LogicException('No request allowed'))), new ArrayAdapter());
        self::assertNull($catalog->lookup('React', '16.14.0'));
        self::assertNull($catalog->lookup('PHP', '>=7.4'));
    }

    public function testEmptyOrOversizedInputHasNoScore(): void
    {
        self::assertNull($this->engine()->analyse([])->score);
        $result = $this->engine()->analyse(['composer.json' => str_repeat('x', 2 * 1024 * 1024 + 1)], false);
        self::assertNotEmpty($result->errors);
        self::assertNull($result->score);
    }

    public function testSameRuntimeEvidenceDoesNotDuplicateFindingOrPenalty(): void
    {
        $result = $this->engine(['result' => ['releases' => [['name' => '7.4', 'eolFrom' => '2022-11-28', 'eoasFrom' => '2021-11-28', 'isMaintained' => false]]]])->analyse(['.php-version' => '7.4', 'Dockerfile' => 'FROM php:7.4-fpm']);
        self::assertCount(1, $result->findings);
        self::assertSame(60, $result->score);
    }

    public function testProductionScopeHasPriorityWhenManifestRepeatsPackage(): void
    {
        $result = $this->engine()->analyse(['package.json' => '{"dependencies":{"example":"^1"},"devDependencies":{"example":"^1"}}'], false);
        self::assertSame('prod', $result->dependencies[0]['scope']);
    }

    /** @param array<string, mixed> $payload */
    private function engine(array $payload = [], int $status = 200): AnalysisEngine
    {
        $http = new CatalogHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => $status])));
        $cache = new ArrayAdapter();

        return new AnalysisEngine(new ComposerParser(), new JavascriptParser(new JavascriptLockParser()), new RuntimeDetector(), new RegistryCatalog($http, $cache), new LifecycleCatalog($http, $cache), new HealthPolicy(), new VersionComparison());
    }

    /** @return array<string, string> */
    private function npmFiles(): array
    {
        return ['package.json' => '{"dependencies":{"example":"^1.0.0"}}', 'package-lock.json' => '{"lockfileVersion":3,"packages":{"node_modules/example":{"version":"1.0.0"}}}'];
    }
}
