<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\ExternalLink;
use App\Domain\User;
use App\Infrastructure\Repository\ExternalLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels du CRUD `/admin/external-links/*`.
 *
 * Couvre : index, création, édition, toggle, suppression, validation CSRF.
 */
final class AdminExternalLinksControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private ExternalLinkRepository $repository;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ExternalLinkRepository::class);

        // Reset de la base : on supprime liens + users de tests précédents.
        $this->em->createQuery('DELETE FROM ' . ExternalLink::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->em->clear();

        $admin = new User('admin-test', 'admin', 'admin@mairie.example.fr', 'Admin');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
    }

    public function testIndexShowsExistingLinks(): void
    {
        $this->persistLink('Mailpit', 'http://localhost:8025', position: 1);
        $this->persistLink('GitLab', 'https://gitlab.example.fr', position: 2);

        $this->client->request('GET', '/admin/external-links');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Mailpit');
        self::assertSelectorTextContains('table', 'GitLab');
    }

    public function testIndexShowsEmptyStateWhenNoLink(): void
    {
        $this->client->request('GET', '/admin/external-links');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun lien configuré');
    }

    public function testCreateNewLink(): void
    {
        $crawler = $this->client->request('GET', '/admin/external-links/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer')->form([
            'external_link[label]' => 'Mailpit',
            'external_link[url]' => 'http://localhost:8025',
            'external_link[icon]' => '📧',
            'external_link[description]' => 'Aperçu local des mails',
            'external_link[position]' => '5',
            'external_link[enabled]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/external-links');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Mailpit » créé');

        $created = $this->repository->findOneBy(['label' => 'Mailpit']);
        self::assertNotNull($created);
        self::assertSame('http://localhost:8025', $created->getUrl());
        self::assertSame(5, $created->getPosition());
        self::assertTrue($created->isEnabled());
    }

    public function testCreateRejectsInvalidUrl(): void
    {
        $crawler = $this->client->request('GET', '/admin/external-links/new');

        $form = $crawler->selectButton('Créer')->form([
            'external_link[label]' => 'Bidon',
            // URL avec uniquement le schéma (pas d'hôte) → rejeté par
            // `Assert\Url`. Pas de `not-a-url` brut : `default_protocol`
            // ferait préfixer `https://` et le validateur l'accepterait
            // (cf. requireTld: false sur le constraint).
            'external_link[url]' => 'https://',
            'external_link[position]' => '0',
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.text-red-700');
        self::assertNull($this->repository->findOneBy(['label' => 'Bidon']));
    }

    public function testEditUpdatesLink(): void
    {
        $link = $this->persistLink('GitLab', 'https://gitlab.example.fr', position: 0);

        $crawler = $this->client->request('GET', '/admin/external-links/' . $link->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form([
            'external_link[label]' => 'GitLab Mairie',
            'external_link[url]' => 'https://git.mairie.example.fr',
            'external_link[position]' => '2',
            'external_link[enabled]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/external-links');
        $this->em->clear();
        $updated = $this->repository->find($link->getId());
        self::assertNotNull($updated);
        self::assertSame('GitLab Mairie', $updated->getLabel());
        self::assertSame('https://git.mairie.example.fr', $updated->getUrl());
        self::assertSame(2, $updated->getPosition());
    }

    public function testToggleFlipsEnabledStatus(): void
    {
        $link = $this->persistLink('Mailpit', 'http://localhost:8025');
        self::assertTrue($link->isEnabled());

        // Le CSRF stateless de Symfony 7.4 calcule le token à partir d'une
        // requête active — on ne peut pas le générer en dehors, donc on
        // soumet le formulaire « Désactiver » rendu sur l'index.
        $crawler = $this->client->request('GET', '/admin/external-links');
        $form = $crawler->filter('form[action="/admin/external-links/' . $link->getId() . '/toggle"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/external-links');
        $this->em->clear();
        $reloaded = $this->repository->find($link->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isEnabled());
    }

    public function testToggleRejectsInvalidCsrfToken(): void
    {
        $link = $this->persistLink('Mailpit', 'http://localhost:8025');

        // Première requête pour démarrer la session ; le POST direct passe
        // ensuite avec un mauvais jeton.
        $this->client->request('GET', '/admin/external-links');
        $this->client->request('POST', '/admin/external-links/' . $link->getId() . '/toggle', [
            '_token' => 'mauvais-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesLink(): void
    {
        $link = $this->persistLink('À supprimer', 'https://x.test');
        $id = $link->getId();

        $crawler = $this->client->request('GET', '/admin/external-links');
        $form = $crawler->filter('form[action="/admin/external-links/' . $id . '"]')->form();
        // Le formulaire embarque un `onsubmit="return confirm(...)"` qui ne
        // joue pas en mode test (pas de JS) — la soumission part directe.
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/external-links');
        $this->em->clear();
        self::assertNull($this->repository->find($id));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $link = $this->persistLink('Protégé', 'https://x.test');
        $id = $link->getId();

        $this->client->request('GET', '/admin/external-links');
        $this->client->request('POST', '/admin/external-links/' . $id, [
            '_token' => 'mauvais-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->repository->find($id));
    }

    private function persistLink(string $label, string $url, int $position = 0): ExternalLink
    {
        $link = new ExternalLink($label, $url);
        $link->setPosition($position);

        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }
}
