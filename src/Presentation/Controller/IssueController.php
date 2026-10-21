<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Service\IssueManager;
use App\Domain\Model\IssueTracking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_ADMIN')]
final class IssueController extends AbstractController
{
    public function __construct(private IssueManager $issues)
    {
    }

    #[Route('/findings/issues/preview', name: 'app_issues_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->csrf($request, 'issue-preview');
        try {
            $draft = $this->issues->preview($this->ids($request));
        } catch (\OutOfBoundsException) {
            throw new NotFoundHttpException('Constat introuvable.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        }

        return $this->render('issues/preview.html.twig', ['draft' => $draft]);
    }

    #[Route('/findings/issues', name: 'app_issues_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->csrf($request, 'issue-create');
        try {
            $issue = $this->issues->create($this->ids($request), $request->request->getString('title'), $request->request->getString('body'), $this->getUser()?->getUserIdentifier() ?? 'unknown');
        } catch (\OutOfBoundsException) {
            throw new NotFoundHttpException('Constat introuvable.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        }
        $this->addFlash(in_array($issue->status, ['failed', 'unknown', 'creating'], true) ? 'error' : 'success', match ($issue->status) {
            'failed' => 'Le fournisseur a refusé la création. Vérifiez les permissions et le quota.',
            'unknown', 'creating' => 'Résultat incertain ou création en cours : vérifiez le fournisseur avant toute autre action.',
            default => 'Issue associée à l’application. Une association existante est réutilisée pour éviter les doublons.',
        });

        return $this->redirectToRoute('app_application', ['id' => $issue->repository->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/issues/{id}/refresh', name: 'app_issues_refresh', methods: ['POST'])]
    public function refresh(string $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->csrf($request, 'issue-refresh-'.$id);
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Issue introuvable.');
        }
        $issue = $em->find(IssueTracking::class, $id);
        if (!$issue instanceof IssueTracking) {
            throw $this->createNotFoundException('Issue introuvable.');
        }
        try {
            $this->issues->refresh($issue, $this->getUser()?->getUserIdentifier() ?? 'unknown', $request->request->getString('number'));
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage());
        }
        $this->addFlash(null === $issue->lastError ? 'success' : 'error', null === $issue->lastError ? 'Statut de l’issue actualisé.' : 'Le statut n’a pas pu être vérifié. Réessayez ultérieurement.');

        return $this->redirectToRoute('app_application', ['id' => $issue->repository->id], Response::HTTP_SEE_OTHER);
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** @return list<string> */
    private function ids(Request $request): array
    {
        $ids = $request->request->all('findings');
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Sélection de constats invalide.');
            }
        }

        return array_values($ids);
    }
}
