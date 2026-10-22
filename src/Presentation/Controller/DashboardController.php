<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Persistence\Portfolio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(Portfolio $portfolio): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'stats' => $portfolio->stats(),
            'priorities' => $portfolio->repositories(sort: 'score')['repositories'],
            'recentScans' => $portfolio->scans()['scans'],
            'trend' => $portfolio->trend(),
        ]);
    }
}
