<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels de la liste utilisateurs admin : filtres, recherche.
 */
final class AdminUsersControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->em->clear();

        $this->persistUser('admin-sub', 'admin', 'admin@mairie.example.fr', 'Alice Admin', ['ROLE_USER', 'ROLE_ADMIN'], ['mairie-admins']);
        $this->persistUser('agent-1', 'jdupont', 'jean.dupont@mairie.example.fr', 'Jean Dupont', ['ROLE_USER'], ['mairie-agents']);
        $this->persistUser('agent-2', 'mmartin', 'marie.martin@mairie.example.fr', 'Marie Martin', ['ROLE_USER'], ['mairie-agents', 'chef-projet']);

        $disabled = $this->persistUser('ex-agent', 'pold', 'patrick.old@mairie.example.fr', 'Patrick Old', ['ROLE_USER'], []);
        $disabled->disable();

        $this->em->flush();

        $admin = $this->em->getRepository(User::class)->findOneBy(['authentikId' => 'admin-sub']);
        self::assertNotNull($admin);
        $this->client->loginUser($admin);
    }

    public function testIndexListsAllUsersByDefault(): void
    {
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Admin');
        self::assertSelectorTextContains('body', 'Jean Dupont');
        self::assertSelectorTextContains('body', 'Marie Martin');
        self::assertSelectorTextContains('body', 'Patrick Old');
    }

    public function testSearchFiltersByName(): void
    {
        $this->client->request('GET', '/admin/users', ['q' => 'martin']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Marie Martin');
        self::assertSelectorTextNotContains('table', 'Jean Dupont');
    }

    public function testStatusFilterShowsOnlyActiveOrDisabled(): void
    {
        $this->client->request('GET', '/admin/users', ['status' => 'disabled']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Patrick Old');
        self::assertSelectorTextNotContains('table', 'Alice Admin');

        $this->client->request('GET', '/admin/users', ['status' => 'active']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('table', 'Patrick Old');
        self::assertSelectorTextContains('body', 'Alice Admin');
    }

    public function testRoleFilterShowsOnlyAdmins(): void
    {
        $this->client->request('GET', '/admin/users', ['role' => 'ROLE_ADMIN']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Admin');
        self::assertSelectorTextNotContains('table', 'Jean Dupont');
    }

    public function testShowDisplaysUserDetails(): void
    {
        $jean = $this->em->getRepository(User::class)->findOneBy(['authentikId' => 'agent-1']);
        self::assertNotNull($jean);

        $this->client->request('GET', '/admin/users/' . $jean->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jean Dupont');
        self::assertSelectorTextContains('body', 'jean.dupont@mairie.example.fr');
        self::assertSelectorTextContains('body', 'mairie-agents');
    }

    /**
     * @param list<string> $roles
     * @param list<string> $groups
     */
    private function persistUser(string $sub, string $username, string $email, string $displayName, array $roles, array $groups): User
    {
        $user = new User($sub, $username, $email, $displayName);
        $user->setRoles($roles);
        $user->setGroupsSnapshot($groups);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
