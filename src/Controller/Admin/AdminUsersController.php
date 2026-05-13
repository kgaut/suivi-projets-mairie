<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\User\UserFilter;
use App\Application\User\UserRepositoryInterface;
use App\Domain\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste et détail des utilisateurs (projection locale d'Authentik).
 *
 * Lecture seule côté app : Authentik reste la source de vérité, la fiche
 * détail propose un lien vers l'utilisateur dans Authentik (construit
 * depuis `OIDC_WELL_KNOWN_URL` si défini).
 */
final class AdminUsersController extends AbstractController
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    #[Route('/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = UserFilter::fromQuery($request->query->all());

        return $this->render('admin/users/index.html.twig', [
            'users' => $this->users->search($filter),
            'filter' => $filter,
            'known_groups' => $this->users->listKnownGroups(),
            'roles' => ['ROLE_ADMIN', 'ROLE_USER'],
        ]);
    }

    #[Route('/admin/users/{id}', name: 'admin_users_show', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['id' => 'id'])] User $user): Response
    {
        return $this->render('admin/users/show.html.twig', [
            'user' => $user,
            'authentik_url' => $this->buildAuthentikUserUrl($user),
        ]);
    }

    /**
     * Construit l'URL d'édition de l'utilisateur dans Authentik à partir
     * de `OIDC_WELL_KNOWN_URL` (présent dans .env). Retourne `null` si l'URL
     * d'issuer n'est pas définie ou ne ressemble pas à une URL Authentik.
     */
    private function buildAuthentikUserUrl(User $user): ?string
    {
        $issuer = $_ENV['OIDC_WELL_KNOWN_URL'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            return null;
        }

        // L'interface admin user d'Authentik est sur `/if/admin/#/identity/users`.
        // On ouvre une recherche sur le `sub` plutôt qu'un lien direct (on n'a
        // pas l'ID Authentik numérique côté app, juste le sub UUID).
        $parts = parse_url($issuer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $base = $parts['scheme'] . '://' . $parts['host'];

        return $base . '/if/admin/#/identity/users;%7B%22search%22%3A%22' . urlencode($user->getAuthentikId()) . '%22%7D';
    }
}
