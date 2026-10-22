<?php

declare(strict_types=1);

namespace App\Tests\Analysis;

use App\Domain\Analysis\ComposerParser;
use App\Domain\Analysis\JavascriptLockParser;
use App\Domain\Analysis\JavascriptParser;
use App\Domain\Analysis\RuntimeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManifestParsersTest extends TestCase
{
    public function testLegacyComposerKeepsConstraintsResolvedVersionsAndScopesDistinct(): void
    {
        $dependencies = (new ComposerParser())->parse([
            'composer.json' => '{"require":{"php":">=5.3","symfony/framework-bundle":"~2.8"},"require-dev":{"phpunit/phpunit":"~4.8"}}',
            'composer.lock' => '{"packages":[{"name":"symfony/framework-bundle","version":"v2.8.52"},{"name":"symfony/http-kernel","version":"v2.8.52"}],"packages-dev":[{"name":"phpunit/phpunit","version":"4.8.36"}]}',
        ]);
        $packages = array_column($dependencies, null, 'name');
        self::assertSame('~2.8', $packages['symfony/framework-bundle']['declared']);
        self::assertSame('v2.8.52', $packages['symfony/framework-bundle']['resolved']);
        self::assertTrue($packages['symfony/framework-bundle']['direct']);
        self::assertFalse($packages['symfony/http-kernel']['direct']);
        self::assertSame('dev', $packages['phpunit/phpunit']['scope']);
        self::assertSame('composer.lock', $packages['symfony/framework-bundle']['source']);
        self::assertArrayNotHasKey('php', $packages);
    }

    public function testComposerWithoutLockCannotClaimAnInstalledVersion(): void
    {
        $dependencies = (new ComposerParser())->parse(['composer.json' => '{"require":{"vendor/package":"1.0.0"}}']);
        self::assertSame('1.0.0', $dependencies[0]['declared']);
        self::assertNull($dependencies[0]['resolved']);
        self::assertSame('composer.json', $dependencies[0]['source']);
    }

    public function testNpmDirectAndNestedResolutionsRemainSeparate(): void
    {
        $dependencies = (new JavascriptParser(new JavascriptLockParser()))->parse([
            'package.json' => '{"dependencies":{"react":"^16.0.0"}}',
            'package-lock.json' => '{"lockfileVersion":2,"packages":{"node_modules/react":{"version":"16.14.0"},"node_modules/legacy/node_modules/react":{"version":"15.7.0"}}}',
        ]);
        self::assertCount(2, $dependencies);
        $versions = array_column($dependencies, null, 'resolved');
        self::assertTrue($versions['16.14.0']['direct']);
        self::assertSame('^16.0.0', $versions['16.14.0']['declared']);
        self::assertFalse($versions['15.7.0']['direct']);
        self::assertNull($versions['15.7.0']['declared']);
    }

    public function testJavascriptWithoutLockRetainsOnlyTheDeclaredConstraint(): void
    {
        $dependencies = (new JavascriptParser(new JavascriptLockParser()))->parse(['package.json' => '{"devDependencies":{"typescript":"^5.0.0"}}']);
        self::assertSame('dev', $dependencies[0]['scope']);
        self::assertSame('^5.0.0', $dependencies[0]['declared']);
        self::assertNull($dependencies[0]['resolved']);
    }

    /** @return iterable<string, array{string}> */
    public static function pnpmLocks(): iterable
    {
        yield 'legacy v5' => ["lockfileVersion: 5.4\ndependencies:\n  vue: 2.7.16\npackages:\n  /vue/2.7.16:\n    resolution: {integrity: fixture}\n"];
        yield 'importer v9' => ["lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      vue:\n        specifier: ^2.7.0\n        version: 2.7.16\npackages:\n  vue@2.7.16:\n    resolution: {integrity: fixture}\n"];
    }

    #[DataProvider('pnpmLocks')]
    public function testPnpmRootImportersIdentifyDirectResolvedPackages(string $lock): void
    {
        $entries = (new JavascriptLockParser())->parse('pnpm-lock.yaml', $lock, ['vue' => '^2.7.0']);
        self::assertSame([['name' => 'vue', 'version' => '2.7.16', 'direct' => true]], $entries);
    }

    /** @return iterable<string, array{string}> */
    public static function yarnLocks(): iterable
    {
        yield 'Classic' => ["# yarn lockfile v1\nreact@^16.0.0:\n  version \"16.14.0\"\n"];
        yield 'Berry' => ["__metadata:\n  version: 8\n\"react@npm:^16.0.0\":\n  version: 16.14.0\n"];
    }

    #[DataProvider('yarnLocks')]
    public function testYarnSelectorsMatchTheRootConstraint(string $lock): void
    {
        $entries = (new JavascriptLockParser())->parse('yarn.lock', $lock, ['react' => '^16.0.0']);
        self::assertSame([['name' => 'react', 'version' => '16.14.0', 'direct' => true]], $entries);
    }

    public function testRuntimeFilesDescribeIntentionsWithoutClaimingDeploymentVersions(): void
    {
        $files = ['composer.json' => '{"require":{"php":">=5.3"}}', '.nvmrc' => 'lts/*', '.php-version' => '7.4', 'Dockerfile' => 'FROM node:20-alpine'];
        $technologies = (new RuntimeDetector())->detect($files, []);
        $runtimes = array_values(array_filter($technologies, static fn (array $technology): bool => in_array($technology['name'], ['PHP', 'Node.js'], true) && 'language' !== ($technology['kind'] ?? '')));
        self::assertCount(4, $runtimes);
        self::assertContains('lts/*', array_column($runtimes, 'declared'));
        self::assertContains('>=5.3', array_column($runtimes, 'declared'));
        self::assertSame([null, null, null, null], array_column($runtimes, 'resolved'));
    }
}
