<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Source d'avatar pour un utilisateur — préférence configurée dans /profile.
 *
 * `AUTO` (défaut) suit la cascade définie en `docs/specifications.md` §3.8 :
 * upload local → Authentik (cache) → Gravatar → initiales SVG.
 * Les autres valeurs forcent une source précise (en cas de fallback impossible,
 * on retombe sur les initiales).
 */
enum AvatarSource: string
{
    case AUTO = 'auto';
    case LOCAL = 'local';
    case AUTHENTIK = 'authentik';
    case GRAVATAR = 'gravatar';
    case INITIALS = 'initials';
}
