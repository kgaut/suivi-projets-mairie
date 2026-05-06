<?php

declare(strict_types=1);

namespace App\Application\Event\User;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis lors du premier login d'un utilisateur (réconciliation crée
 * une nouvelle entité `User`). Distinct de `UserLoggedIn` pour pouvoir
 * déclencher un onboarding admin (notification, attribution de rôles, etc.).
 */
final class UserFirstSeen extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'user';
    }

    public function action(): string
    {
        return 'first_seen';
    }
}
