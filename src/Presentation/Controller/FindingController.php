<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Persistence\Portfolio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FindingController extends AbstractController
{
    #[Route('/findings', name: 'app_findings', methods: ['GET'])]
    public function __invoke(Request $request, Portfolio $portfolio): Response
    {
        $q = $request->query->getString('q');
        $severity = $request->query->getString('severity');
        $type = $request->query->getString('type');

        return $this->render('findings/index.html.twig', $portfolio->findings($q, $severity, $type, $request->query->getInt('page', 1)) + compact('q', 'severity', 'type'));
    }
}
