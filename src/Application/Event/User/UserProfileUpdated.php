<?php

declare(strict_types=1);

namespace App\Application\Event\User;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis quand un attribut du profil change côté Authentik et est répercuté
 * lors d'un login (e-mail, displayName, groupes, etc.).
 * Le contexte porte les diff `before` / `after`.
 */
final class UserProfileUpdated extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'user';
    }

    public function action(): string
    {
        return 'profile.updated';
    }
}
