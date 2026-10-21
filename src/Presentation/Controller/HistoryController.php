<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Persistence\Portfolio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history', methods: ['GET'])]
    public function __invoke(Request $request, Portfolio $portfolio): Response
    {
        return $this->render('history/index.html.twig', $portfolio->scans(page: $request->query->getInt('page', 1)));
    }
}
