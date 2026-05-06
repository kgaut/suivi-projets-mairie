<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Enum\AvatarSource;
use App\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Projection locale d'un utilisateur Authentik.
 *
 * L'utilisateur n'est pas géré dans l'app : c'est Authentik la source de
 * vérité. On conserve cette projection pour rattacher les contributions et
 * afficher des infos lisibles sans appel synchrone à Authentik à chaque
 * requête.
 *
 * Voir `docs/specifications.md` §3.8 (sémantique métier) et
 * `docs/modele-de-donnees.md` §3.8 (structure technique).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['email'], name: 'idx_users_email')]
#[ORM\UniqueConstraint(name: 'uq_users_authentik_id', columns: ['authentik_id'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * `sub` du token OIDC Authentik. Clé de réconciliation, immuable.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $authentikId;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $username;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $displayName;

    /**
     * Rôles applicatifs résolus au login. Contient toujours `ROLE_USER`.
     * Contient `ROLE_ADMIN` si l'utilisateur est membre de `OIDC_ADMIN_GROUP`.
     * Les autres rôles (`ROLE_CHEF_PROJET`, `ROLE_ACTEUR`, `ROLE_LECTEUR`) sont
     * calculés dynamiquement par les voters et **ne sont pas stockés ici**.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_USER'];

    /**
     * Claim `groups` capté au dernier login OIDC. Sert au calcul dynamique des
     * rôles (cf. specs §2) et à l'appartenance aux groupes de travail (§3.11).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $groupsSnapshot = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * Détecté désactivé côté Authentik (compte supprimé, refus du filtrage
     * `OIDC_REQUIRED_GROUPS`, etc.). On conserve la ligne pour ne pas casser
     * l'historique des contributions.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    // -- Avatars (cf. specs §3.8 sous-section Avatar) ------------------------

    /**
     * Chemin opaque (côté `AttachmentStorage`) de l'avatar uploadé localement
     * par l'utilisateur depuis `/profile`. Prioritaire dans la cascade.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $avatarPath = null;

    /**
     * URL d'origine du claim `picture` Authentik, servait à détecter un
     * changement de l'avatar côté IdP entre deux logins.
     */
    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $authentikAvatarSourceUrl = null;

    /**
     * Chemin opaque du cache local de l'avatar Authentik (téléchargé pour
     * éviter une dépendance runtime à l'IdP).
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $authentikAvatarPath = null;

    /**
     * Date du dernier téléchargement réussi de l'avatar Authentik. TTL 24 h
     * avant nouveau fetch (cf. specs §3.8).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $authentikAvatarFetchedAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AvatarSource::class)]
    private AvatarSource $avatarSource = AvatarSource::AUTO;

    /**
     * Préférence RGPD : autorise l'app à interroger gravatar.com avec le hash
     * SHA-256 de l'e-mail si aucune autre source n'est dispo.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $gravatarAllowed = true;

    // -- Audit timestamps ----------------------------------------------------

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $authentikId,
        string $username,
        string $email,
        string $displayName,
    ) {
        $this->id = Uuid::v7();
        $this->authentikId = $authentikId;
        $this->username = $username;
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAuthentikId(): string
    {
        return $this->authentikId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
        $this->touch();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
        $this->touch();
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getGroupsSnapshot(): array
    {
        return $this->groupsSnapshot;
    }

    /**
     * @param list<string> $groups
     */
    public function setGroupsSnapshot(array $groups): void
    {
        $this->groupsSnapshot = array_values($groups);
        $this->touch();
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(?\DateTimeImmutable $at = null): void
    {
        $this->lastLoginAt = $at ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function disable(?\DateTimeImmutable $at = null): void
    {
        $this->disabledAt = $at ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $path): void
    {
        $this->avatarPath = $path;
        $this->touch();
    }

    public function getAuthentikAvatarSourceUrl(): ?string
    {
        return $this->authentikAvatarSourceUrl;
    }

    public function getAuthentikAvatarPath(): ?string
    {
        return $this->authentikAvatarPath;
    }

    public function getAuthentikAvatarFetchedAt(): ?\DateTimeImmutable
    {
        return $this->authentikAvatarFetchedAt;
    }

    public function setAuthentikAvatar(string $sourceUrl, string $path, \DateTimeImmutable $fetchedAt): void
    {
        $this->authentikAvatarSourceUrl = $sourceUrl;
        $this->authentikAvatarPath = $path;
        $this->authentikAvatarFetchedAt = $fetchedAt;
        $this->touch();
    }

    public function clearAuthentikAvatar(): void
    {
        $this->authentikAvatarSourceUrl = null;
        $this->authentikAvatarPath = null;
        $this->authentikAvatarFetchedAt = null;
        $this->touch();
    }

    public function getAvatarSource(): AvatarSource
    {
        return $this->avatarSource;
    }

    public function setAvatarSource(AvatarSource $source): void
    {
        $this->avatarSource = $source;
        $this->touch();
    }

    public function isGravatarAllowed(): bool
    {
        return $this->gravatarAllowed;
    }

    public function setGravatarAllowed(bool $allowed): void
    {
        $this->gravatarAllowed = $allowed;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Identifiant retourné à Symfony Security. On utilise `authentikId` (clé
     * stable, immuable) plutôt que l'email ou le username qui peuvent évoluer
     * côté Authentik.
     */
    public function getUserIdentifier(): string
    {
        return $this->authentikId;
    }

    public function eraseCredentials(): void
    {
        // No-op : aucun secret stocké dans cette projection (auth via Authentik).
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
