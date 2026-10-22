<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'app_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok', headers: ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain']);
    }
}
