<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Service\AuditTrail;
use App\Application\Service\ConfigurationStore;
use App\Presentation\Form\SettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ConfigurationStore $store, AuditTrail $audit, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SettingsType::class, $store->values());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = [];
            foreach (array_keys(ConfigurationStore::DEFAULTS) as $name) {
                $value = $form->get($name)->getData();
                if (!is_int($value)) {
                    throw new \LogicException('Validated setting is not an integer.');
                }
                $values[$name] = $value;
            }
            $store->save($values);
            $audit->record('settings.updated', 'configuration', $this->getUser()?->getUserIdentifier() ?? 'unknown');
            $em->flush();
            $this->addFlash('success', 'Paramètres enregistrés. Les prochains traitements utiliseront ces réglages.');

            return $this->redirectToRoute('app_settings', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('settings/index.html.twig', ['form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
