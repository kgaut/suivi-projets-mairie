<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie le verrou ROLE_ADMIN sur la section /admin.
 *
 * Cf. config/packages/security.yaml — toute la section `/admin` est
 * derrière `roles: ROLE_ADMIN`. L'auth OIDC réelle est désactivée en
 * test (cf. security.yaml `when@test`), on injecte un user via
 * `$client->loginUser()`.
 */
final class AdminAccessTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Repart d'une base saine : on supprime tout user de la fixture précédente
        // pour pouvoir recréer les comptes du test sans contrainte d'unicité.
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->em->clear();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin');

        // Symfony redirige les anonymes vers l'entry point d'auth (/login).
        self::assertResponseRedirects();
    }

    public function testNonAdminUserGetsAccessDenied(): void
    {
        $user = $this->createPersistedUser('user-sub', 'agent', 'agent@mairie.example.fr', ['ROLE_USER']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessDashboard(): void
    {
        $admin = $this->createPersistedUser('admin-sub', 'admin', 'admin@mairie.example.fr', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
    }

    public function testAdminCanAccessUsersAndExternalLinks(): void
    {
        $admin = $this->createPersistedUser('admin-2', 'admin2', 'admin2@mairie.example.fr', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/external-links');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function createPersistedUser(string $sub, string $username, string $email, array $roles): User
    {
        $user = new User($sub, $username, $email, $username);
        $user->setRoles($roles);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
