<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\ExternalLink\ExternalLinkRepositoryInterface;
use App\Application\User\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord admin : un index simple avec quelques compteurs et
 * raccourcis vers les sections de gestion. Le verrou ROLE_ADMIN est
 * appliqué par `access_control` (cf. config/packages/security.yaml).
 */
final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ExternalLinkRepositoryInterface $externalLinks,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'user_count' => $this->users->countAll(),
            'active_user_count' => $this->users->countActive(),
            'external_link_count' => $this->externalLinks->countAll(),
        ]);
    }
}
