<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Service\AuditTrail;
use App\Application\Service\RepositoryScope;
use App\Application\Service\RequestJobs;
use App\Domain\Git\ProviderException;
use App\Domain\Model\ProviderConnection;
use App\Domain\Model\Repository;
use App\Domain\Model\SyncJob;
use App\Infrastructure\Git\GitEndpointPolicy;
use App\Infrastructure\Git\GitProviderFactory;
use App\Infrastructure\Security\SecretCipher;
use App\Presentation\Form\ConnectionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/connections')]
final class ConnectionController extends AbstractController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private EntityManagerInterface $em, private SecretCipher $cipher, private GitEndpointPolicy $policy, private GitProviderFactory $providers, private RequestJobs $jobs, private AuditTrail $audit, private RepositoryScope $scope, private LockFactory $locks)
    {
    }

    #[Route('', name: 'app_connections', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('connections/index.html.twig', ['connections' => $this->em->getRepository(ProviderConnection::class)->findBy([], ['name' => 'ASC'], 1000)]);
    }

    #[Route('/new', name: 'app_connection_new', methods: ['GET'])]
    #[Route('', name: 'app_connection_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->editForm($request, new ProviderConnection(), true);
    }

    #[Route('/{id}', name: 'app_connection', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] ProviderConnection $connection): Response
    {
        return $this->render('connections/show.html.twig', ['connection' => $connection, 'jobs' => $this->em->getRepository(SyncJob::class)->findBy(['connection' => $connection], ['createdAt' => 'DESC'], 20)]);
    }

    #[Route('/{id}/edit', name: 'app_connection_update', requirements: ['id' => self::UUID], methods: ['GET', 'POST'])]
    public function update(Request $request, #[MapEntity(id: 'id')] ProviderConnection $connection): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->editForm($request, $connection, false);
        }
        $lock = $this->locks->createLock('sync-'.$connection->id);
        if (!$lock->acquire()) {
            $form = $this->createForm(ConnectionType::class, $connection, ['action' => $this->generateUrl('app_connection_update', ['id' => $connection->id])]);
            $form->addError(new FormError('Synchronisation en cours. Attendez sa fin avant de modifier cette connexion.'));

            return $this->render('connections/form.html.twig', ['form' => $form->createView(), 'connection' => $connection], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        try {
            $this->em->refresh($connection);

            return $this->editForm($request, $connection, false);
        } finally {
            $lock->release();
        }
    }

    #[Route('/{id}/test', name: 'app_connection_test', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function test(Request $request, #[MapEntity(id: 'id')] ProviderConnection $connection): Response
    {
        $this->verifyToken($request, 'test-'.$connection->id);
        $connection->lastTestedAt = new \DateTimeImmutable();
        try {
            $this->providers->forConnection($connection)->test();
            $connection->status = 'connected';
            $connection->lastError = null;
            $this->addFlash('success', 'Connexion vérifiée.');
        } catch (ProviderException $error) {
            $connection->status = 'failed';
            $connection->lastError = $error->getMessage();
            $this->addFlash('error', $connection->lastError);
        } catch (\Throwable) {
            $connection->status = 'failed';
            $connection->lastError = 'Connexion impossible. Vérifiez la configuration et la clé de chiffrement.';
            $this->addFlash('error', $connection->lastError);
        }
        $this->audit->record('connection.tested', $connection->id, $this->actor());
        $this->em->flush();

        return $this->redirectToRoute('app_connection', ['id' => $connection->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/sync', name: 'app_connection_sync', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function synchronize(Request $request, #[MapEntity(id: 'id')] ProviderConnection $connection): Response
    {
        $this->verifyToken($request, 'sync-'.$connection->id);
        try {
            $this->jobs->synchronize($connection, $this->actor());
            $this->addFlash('success', 'Synchronisation placée en file d’attente.');
        } catch (\DomainException $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_connection', ['id' => $connection->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/disable', name: 'app_connection_disable', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function disable(Request $request, #[MapEntity(id: 'id')] ProviderConnection $connection): Response
    {
        $this->verifyToken($request, 'disable-'.$connection->id);
        $connection->active = false;
        $this->audit->record('connection.disabled', $connection->id, $this->actor());
        $this->em->flush();
        $this->addFlash('success', 'Connexion désactivée.');

        return $this->redirectToRoute('app_connection', ['id' => $connection->id], Response::HTTP_SEE_OTHER);
    }

    private function editForm(Request $request, ProviderConnection $connection, bool $new): Response
    {
        $originalProvider = $connection->provider;
        $originalUrl = $connection->baseUrl;
        $originalOrganizations = $connection->organizations;
        $originalIncluded = $connection->includedRepositories;
        $originalExcluded = $connection->excludedRepositories;
        $form = $this->createForm(ConnectionType::class, $connection, ['new_connection' => $new, 'action' => $this->generateUrl($new ? 'app_connection_create' : 'app_connection_update', $new ? [] : ['id' => $connection->id])]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$new && $connection->provider !== $originalProvider) {
                $form->get('provider')->addError(new FormError('Le fournisseur est immuable. Créez une nouvelle connexion pour changer de plateforme.'));
            }
            if (!$new && $connection->baseUrl !== $originalUrl) {
                $form->get('baseUrl')->addError(new FormError('L’instance est immuable. Créez une nouvelle connexion pour changer d’instance.'));
            }
            $identityChanged = !$new && ($connection->provider !== $originalProvider || $connection->baseUrl !== $originalUrl);
            try {
                $this->policy->validate($connection->baseUrl, true);
                $token = $form->get('token')->getData();
                if (!$identityChanged && is_string($token) && '' !== $token) {
                    $connection->encryptedToken = $this->cipher->encrypt($token);
                    $connection->status = 'untested';
                    $connection->lastError = null;
                }
            } catch (ProviderException $error) {
                $form->get('baseUrl')->addError(new FormError($error->getMessage()));
            } catch (\RuntimeException) {
                $form->addError(new FormError('Le chiffrement est indisponible. Contactez l’administrateur.'));
            }
            if ($form->isValid()) {
                $this->em->wrapInTransaction(function () use ($connection, $new, $originalOrganizations, $originalIncluded, $originalExcluded): void {
                    if (!$new && ($connection->organizations !== $originalOrganizations || $connection->includedRepositories !== $originalIncluded || $connection->excludedRepositories !== $originalExcluded)) {
                        $connection->nextSyncAt = null;
                        $this->invalidateScope($connection, $connection->organizations !== $originalOrganizations);
                    }
                    $this->em->persist($connection);
                    $this->audit->record($new ? 'connection.created' : 'connection.updated', $connection->id, $this->actor());
                    $this->em->flush();
                });
                $this->addFlash('success', 'Connexion enregistrée.');

                return $this->redirectToRoute('app_connection', ['id' => $connection->id], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('connections/form.html.twig', ['form' => $form->createView(), 'connection' => $new ? null : $connection], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function invalidateScope(ProviderConnection $connection, bool $organizationsChanged): void
    {
        if ($organizationsChanged) {
            $this->em->createQueryBuilder()->update(Repository::class, 'r')->set('r.monitored', 'false')
                ->where('r.connection = :connection')->setParameter('connection', $connection)->getQuery()->execute();

            return;
        }
        $repositories = $this->em->getRepository(Repository::class)->findBy(['connection' => $connection]);
        foreach ($repositories as $repository) {
            $repository->monitored = $repository->monitored && $this->scope->includes($connection, $repository->name);
        }
    }

    private function verifyToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function actor(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'unknown';
    }
}
