<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Initial navigation until each feature replaces its own route. */
final class WorkspaceController extends AbstractController
{
    #[Route('/applications', name: 'app_applications', defaults: ['title' => 'Applications'], methods: ['GET'])]
    #[Route('/connections', name: 'app_connections', defaults: ['title' => 'Connexions'], methods: ['GET'])]
    #[Route('/findings', name: 'app_findings', defaults: ['title' => 'Alertes et recommandations'], methods: ['GET'])]
    #[Route('/history', name: 'app_history', defaults: ['title' => 'Historique des analyses'], methods: ['GET'])]
    #[Route('/settings', name: 'app_settings', defaults: ['title' => 'Paramètres'], methods: ['GET'])]
    public function __invoke(string $title): Response
    {
        return $this->render('section/empty.html.twig', [
            'title' => $title,
            'message' => 'Cette fonctionnalité sera disponible dans un prochain incrément. Aucun résultat ne peut encore être affiché.',
        ]);
    }
}
