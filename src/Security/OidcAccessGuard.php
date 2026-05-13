<?php

declare(strict_types=1);

namespace App\Security;

use App\Application\Event\Security\AccessDenied;
use App\Application\Event\User\UserDisabled;
use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Filtrage d'accès à l'application (defense in depth, cf. specs §5.3).
 *
 * Vérifie qu'un utilisateur authentifié par Authentik appartient à au moins
 * un des groupes listés dans `OIDC_REQUIRED_GROUPS`. Sinon, l'authentification
 * est rejetée et la projection locale est marquée `disabledAt` pour préserver
 * l'historique des contributions sans permettre une nouvelle session.
 *
 * S'ajoute à la Policy Binding côté Authentik (cf. docs/authentik.md §1.5)
 * pour limiter l'impact d'une mauvaise configuration côté IdP.
 */
final readonly class OidcAccessGuard
{
    /**
     * @var list<string>
     */
    private array $requiredGroups;

    public function __construct(
        string $requiredGroupsCsv,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        $this->requiredGroups = $this->parseCsv($requiredGroupsCsv);
    }

    /**
     * @param list<string> $userGroups Groupes Authentik de l'utilisateur (claim `groups`)
     *
     * @throws CustomUserMessageAuthenticationException si l'utilisateur n'a aucun groupe requis
     */
    public function ensureUserIsAllowed(User $user, array $userGroups): void
    {
        // Pas de filtrage configuré → tout le monde passe (mais Policy Binding
        // côté Authentik reste en place comme première ligne de défense).
        if ($this->requiredGroups === []) {
            return;
        }

        $intersection = array_values(array_intersect($userGroups, $this->requiredGroups));
        if ($intersection !== []) {
            return;
        }

        $this->logger->warning('OIDC access denied: user groups do not intersect required groups', [
            'authentik_id' => $user->getAuthentikId(),
            'user_groups' => $userGroups,
            'required_groups' => $this->requiredGroups,
        ]);

        $wasActive = !$user->isDisabled();

        // Marque l'utilisateur désactivé localement (pas de suppression — on
        // garde le sub Authentik pour préserver l'historique futur).
        $user->disable();
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new AccessDenied(
            subjectAuthentikId: $user->getAuthentikId(),
            context: [
                'reason' => 'required_groups_mismatch',
                'user_groups' => $userGroups,
                'required_groups' => $this->requiredGroups,
            ],
        ));

        // Émet UserDisabled uniquement si on vient effectivement de changer
        // l'état (évite la répétition à chaque tentative d'un user déjà
        // désactivé qui retenterait via Authentik).
        if ($wasActive) {
            $this->eventDispatcher->dispatch(new UserDisabled(
                subjectAuthentikId: $user->getAuthentikId(),
                context: ['reason' => 'required_groups_mismatch'],
            ));
        }

        throw new CustomUserMessageAuthenticationException('Accès non autorisé : votre compte n\'appartient à aucun groupe autorisé pour cette application.');
    }

    /**
     * @return list<string>
     */
    public function getRequiredGroups(): array
    {
        return $this->requiredGroups;
    }

    /**
     * @return list<string>
     */
    private function parseCsv(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $groups = array_map(trim(...), explode(',', $csv));

        return array_values(array_filter($groups, static fn (string $g): bool => $g !== ''));
    }
}
