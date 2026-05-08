<?php

declare(strict_types=1);

namespace App\Security;

use App\Application\Service\Avatar\AuthentikAvatarFetcher;
use App\Domain\User;
use App\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Model\OidcUserData;
use Drenso\OidcBundle\Security\UserProvider\OidcUserProviderInterface;
use InvalidArgumentException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * UserProvider applicatif qui :
 *
 * - implémente l'interface drenso pour piloter la réconciliation au login
 *   (création OU mise à jour des champs Authentik à chaque connexion)
 * - implémente l'interface Symfony pour servir le User aux firewalls
 *
 * Calcule également le rôle `ROLE_ADMIN` (statique, basé sur le groupe
 * Authentik défini par `OIDC_ADMIN_GROUP`). Les autres rôles
 * (`ROLE_CHEF_PROJET`, `ROLE_ACTEUR`, `ROLE_LECTEUR`) sont calculés
 * dynamiquement par les voters (cf. `docs/specifications.md` §2).
 *
 * @implements OidcUserProviderInterface<User>
 */
final readonly class OidcUserProvider implements OidcUserProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private OidcAccessGuard $accessGuard,
        private AuthentikAvatarFetcher $avatarFetcher,
        private string $adminGroup,
    ) {
    }

    public function ensureUserExists(string $userIdentifier, OidcUserData $userData, OidcTokens $tokens): void
    {
        $user = $this->users->findOneByAuthentikId($userIdentifier);
        if (!$user instanceof User) {
            $user = new User(
                authentikId: $userIdentifier,
                username: $this->resolveUsername($userData, $userIdentifier),
                email: $userData->getEmail(),
                displayName: $this->resolveDisplayName($userData),
            );
            $this->entityManager->persist($user);
        } else {
            // Mise à jour des champs Authentik à chaque login (peuvent changer côté IdP)
            $user->setUsername($this->resolveUsername($userData, $userIdentifier));
            $user->setEmail($userData->getEmail());
            $user->setDisplayName($this->resolveDisplayName($userData));
        }

        $groups = $this->resolveGroups($userData);
        $user->setGroupsSnapshot($groups);
        $user->setRoles($this->resolveRoles($groups));
        $user->recordLogin();

        $this->entityManager->flush();

        // Filtrage defense in depth (cf. specs §5.3) : l'utilisateur doit
        // appartenir à au moins un des OIDC_REQUIRED_GROUPS. En cas de rejet,
        // le user est désactivé localement et une exception interrompt l'auth.
        $this->accessGuard->ensureUserIsAllowed($user, $groups);

        // Cache l'avatar Authentik (claim `picture`) localement si présent
        // et si pas trop ancien (TTL 24 h). Échec silencieux : ne casse
        // jamais le login (fallback Gravatar / initiales par UserAvatarResolver).
        $picture = $userData->getUserDataString('picture');
        $this->avatarFetcher->fetchIfNeeded($user, $picture !== '' ? $picture : null);
    }

    public function loadOidcUser(string $userIdentifier): UserInterface
    {
        return $this->loadUserByIdentifier($userIdentifier);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users->findOneByAuthentikId($identifier);
        if (!$user instanceof User) {
            throw new UserNotFoundException(\sprintf('User with authentikId "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new InvalidArgumentException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getAuthentikId());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string>
     */
    private function resolveRoles(array $groups): array
    {
        $roles = ['ROLE_USER'];
        if ($this->adminGroup !== '' && in_array($this->adminGroup, $groups, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    private function resolveUsername(OidcUserData $userData, string $fallback): string
    {
        $username = $userData->getUserDataString('preferred_username');

        return $username !== '' ? $username : $fallback;
    }

    private function resolveDisplayName(OidcUserData $userData): string
    {
        $name = $userData->getUserDataString('name');
        if ($name !== '') {
            return $name;
        }

        // Fallback : reconstitué à partir de given_name + family_name si dispo,
        // sinon email.
        $given = $userData->getUserDataString('given_name');
        $family = $userData->getUserDataString('family_name');
        $reconstructed = trim($given . ' ' . $family);

        return $reconstructed !== '' ? $reconstructed : $userData->getEmail();
    }

    /**
     * @return list<string>
     */
    private function resolveGroups(OidcUserData $userData): array
    {
        try {
            $groups = $userData->getUserDataArray('groups');
        } catch (Throwable) {
            // Le claim `groups` peut être absent du userinfo selon la config Authentik
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($g): ?string => is_string($g) ? $g : null, $groups),
            static fn (?string $g): bool => $g !== null && $g !== '',
        ));
    }
}
