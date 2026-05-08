<?php

declare(strict_types=1);

namespace App\Application\Event\Security;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis après un login OIDC réussi (callback Authentik OK + accessGuard OK).
 * Dispatché depuis le code OIDC (Vague 2) — le wiring sera ajouté
 * lors de la fusion des deux vagues.
 */
final class UserLoggedIn extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'security';
    }

    public function action(): string
    {
        return 'login.success';
    }
}
