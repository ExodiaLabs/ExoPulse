<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Service\RequestJobs;
use App\Domain\Model\Finding;
use App\Domain\Model\IssueTracking;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Infrastructure\Persistence\Portfolio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class ApplicationController extends AbstractController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    #[Route('/applications', name: 'app_applications', methods: ['GET'])]
    public function index(Request $request, Portfolio $portfolio, EntityManagerInterface $em): Response
    {
        $q = $request->query->getString('q');
        $status = $request->query->getString('status');
        $sort = $request->query->getString('sort', 'name');
        $connection = $request->query->getString('connection');
        $data = $portfolio->repositories($q, $status, $sort, $request->query->getInt('page', 1), Uuid::isValid($connection) ? $connection : '');

        return $this->render('applications/index.html.twig', $data + compact('q', 'status', 'sort', 'connection') + ['connections' => $em->getRepository(ProviderConnection::class)->findBy([], ['name' => 'ASC'], 1000)]);
    }

    #[Route('/applications/{id}', name: 'app_application', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] Repository $repository, EntityManagerInterface $em): Response
    {
        return $this->render('applications/show.html.twig', [
            'repository' => $repository,
            'scan' => $repository->latestScan,
            'findings' => $em->getRepository(Finding::class)->findBy(['repository' => $repository, 'resolvedAt' => null], ['firstSeenAt' => 'ASC'], 200),
            'issues' => $em->getRepository(IssueTracking::class)->findBy(['repository' => $repository], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/applications/{id}/dependencies', name: 'app_application_dependencies', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function dependencies(#[MapEntity(id: 'id')] Repository $repository): Response
    {
        return $this->render('applications/dependencies.html.twig', ['repository' => $repository, 'scan' => $repository->latestScan]);
    }

    #[Route('/applications/{id}/history', name: 'app_application_history', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function history(#[MapEntity(id: 'id')] Repository $repository, Request $request, Portfolio $portfolio): Response
    {
        return $this->render('applications/history.html.twig', ['repository' => $repository] + $portfolio->scans($repository, $request->query->getInt('page', 1)));
    }

    #[Route('/applications/{id}/scan', name: 'app_scan', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function scan(#[MapEntity(id: 'id')] Repository $repository, Request $request, RequestJobs $jobs): Response
    {
        if (!$this->isCsrfTokenValid('scan-'.$repository->id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try {
            foreach ($jobs->branches($repository) as $branch) {
                $jobs->scan($repository, $this->getUser()?->getUserIdentifier() ?? 'administrator', $branch);
            }
            $this->addFlash('success', 'Analyse mise en attente. Son état est visible dans l’historique.');
        } catch (\DomainException $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_application_history', ['id' => $repository->id], Response::HTTP_SEE_OTHER);
    }
}
