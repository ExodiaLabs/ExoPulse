<?php

declare(strict_types=1);

namespace App\Tests\UI;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class DependenciesViewTest extends TestCase
{
    public function testDeclaredAndResolvedVersionsStayDistinctAndRepositoryTextIsEscaped(): void
    {
        $view = new Environment(new FilesystemLoader(\dirname(__DIR__, 2).'/templates'), ['strict_variables' => true, 'autoescape' => 'html']);
        $view->addFunction(new TwigFunction('path', static fn (string $route): string => '/'.$route));
        $view->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => false));
        $view->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
        $view->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'synthetic-csrf'));
        $view->addGlobal('app', ['current_route' => 'app_application_dependencies', 'flashes' => []]);
        $html = $view->render('applications/dependencies.html.twig', [
            'repository' => ['name' => '<script>unexpected()</script>'],
            'scan' => ['results' => [
                'technologies' => [['name' => 'PHP', 'declared' => '^8.2', 'resolved' => null]],
                'dependencies' => [['name' => 'symfony/framework-bundle', 'declared' => '^7.0', 'resolved' => '7.0.3', 'latest' => null, 'direct' => true]],
                'errors' => ['Source de versions indisponible'],
            ]],
        ]);

        self::assertStringContainsString('^7.0', $html);
        self::assertStringContainsString('7.0.3', $html);
        self::assertStringContainsString('Indisponible', $html);
        self::assertStringContainsString('Source de versions indisponible', $html);
        self::assertStringContainsString('&lt;script&gt;unexpected()', $html);
        self::assertStringNotContainsString('<script>unexpected()', $html);
    }
}
