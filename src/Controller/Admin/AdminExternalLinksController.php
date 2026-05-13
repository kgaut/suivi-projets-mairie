<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\ExternalLink\ExternalLinkRepositoryInterface;
use App\Domain\ExternalLink;
use App\Form\ExternalLinkInput;
use App\Form\ExternalLinkType;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD admin des liens externes affichés dans le lanceur d'apps.
 *
 * Cf. specs §3.12. Pas de restriction par rôle côté affichage public :
 * tout user authentifié voit les liens actifs. La gestion (création /
 * édition / suppression / toggle) est réservée aux administrateurs.
 */
final class AdminExternalLinksController extends AbstractController
{
    public function __construct(
        private readonly ExternalLinkRepositoryInterface $repository,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('/admin/external-links', name: 'admin_external_links_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/external_links/index.html.twig', [
            'links' => $this->repository->findAllOrdered(),
        ]);
    }

    #[Route('/admin/external-links/new', name: 'admin_external_links_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $input = new ExternalLinkInput();
        $form = $this->formFactory->create(ExternalLinkType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $link = $input->toNewEntity();
            $this->repository->save($link);
            $this->addFlash('success', \sprintf('Lien « %s » créé.', $link->getLabel()));

            return $this->redirectToRoute('admin_external_links_index');
        }

        return $this->render('admin/external_links/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/admin/external-links/{id}/edit', name: 'admin_external_links_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['id' => 'id'])]
        ExternalLink $link,
    ): Response {
        $input = ExternalLinkInput::fromEntity($link);
        $form = $this->formFactory->create(ExternalLinkType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $input->applyTo($link);
            $this->repository->save($link);
            $this->addFlash('success', \sprintf('Lien « %s » mis à jour.', $link->getLabel()));

            return $this->redirectToRoute('admin_external_links_index');
        }

        return $this->render('admin/external_links/edit.html.twig', [
            'form' => $form->createView(),
            'link' => $link,
        ]);
    }

    #[Route('/admin/external-links/{id}/toggle', name: 'admin_external_links_toggle', methods: ['POST'])]
    public function toggle(
        Request $request,
        #[MapEntity(mapping: ['id' => 'id'])]
        ExternalLink $link,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('external_link_toggle_' . $link->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($link->isEnabled()) {
            $link->disable();
            $message = \sprintf('Lien « %s » désactivé.', $link->getLabel());
        } else {
            $link->enable();
            $message = \sprintf('Lien « %s » activé.', $link->getLabel());
        }

        $this->repository->save($link);
        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_external_links_index');
    }

    #[Route('/admin/external-links/{id}', name: 'admin_external_links_delete', methods: ['POST', 'DELETE'])]
    public function delete(
        Request $request,
        #[MapEntity(mapping: ['id' => 'id'])]
        ExternalLink $link,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('external_link_delete_' . $link->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $label = $link->getLabel();
        $this->repository->remove($link);
        $this->addFlash('success', \sprintf('Lien « %s » supprimé.', $label));

        return $this->redirectToRoute('admin_external_links_index');
    }
}
