<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Persistence\Portfolio;
use App\Infrastructure\Persistence\TechnicalPortfolio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(Portfolio $portfolio, TechnicalPortfolio $technical): Response
    {
        $priorities = $technical->repositories(['sort' => 'urgency']);

        return $this->render('dashboard/index.html.twig', [
            'technicalStats' => $technical->stats(),
            'technologyInventory' => $technical->technologyInventory(),
            'diagnostics' => $priorities['diagnostics'],
            'stats' => $portfolio->stats(),
            'priorities' => $priorities['repositories'],
            'recentScans' => $portfolio->scans()['scans'],
            'trend' => $portfolio->trend(),
        ]);
    }
}
