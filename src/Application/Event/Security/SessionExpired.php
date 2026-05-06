<?php

declare(strict_types=1);

namespace App\Application\Event\Security;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis quand une session est expirée (timeout, révocation côté Authentik, etc.).
 * Le user n'est pas forcément en train d'agir — l'événement peut être
 * dispatché par un nettoyage de fond.
 */
final class SessionExpired extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'security';
    }

    public function action(): string
    {
        return 'session.expired';
    }
}
