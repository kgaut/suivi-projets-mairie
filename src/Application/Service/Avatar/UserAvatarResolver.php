<?php

declare(strict_types=1);

namespace App\Application\Service\Avatar;

use App\Application\Storage\AttachmentStorageInterface;
use App\Domain\Enum\AvatarSource;
use App\Domain\User;

/**
 * Résout l'avatar à afficher pour un utilisateur en suivant la cascade
 * définie en `docs/specifications.md` §3.8 :
 *
 *   1. Upload local (`User::avatarPath`)         — prioritaire si présent
 *   2. Authentik (cache local)                   — `User::authentikAvatarPath`
 *   3. Gravatar                                  — si `gravatarAllowed=true` + email
 *   4. Initiales SVG                             — fallback final
 *
 * La préférence utilisateur `avatarSource` peut forcer une source précise.
 * En cas de fallback impossible (ex. `LOCAL` mais `avatarPath=null`), on
 * retombe sur les initiales pour ne jamais avoir un avatar cassé à l'écran.
 */
final readonly class UserAvatarResolver
{
    private const string GRAVATAR_BASE = 'https://gravatar.com/avatar/';

    /**
     * Palette stable de 12 couleurs (fonds des avatars initiales).
     * Indexée par `crc32($authentikId) % 12` pour qu'un même utilisateur
     * ait toujours la même couleur.
     *
     * @var list<string>
     */
    private const array INITIALS_PALETTE = [
        '#ef4444', // red-500
        '#f97316', // orange-500
        '#f59e0b', // amber-500
        '#84cc16', // lime-500
        '#22c55e', // green-500
        '#10b981', // emerald-500
        '#14b8a6', // teal-500
        '#06b6d4', // cyan-500
        '#3b82f6', // blue-500
        '#6366f1', // indigo-500
        '#8b5cf6', // violet-500
        '#ec4899', // pink-500
    ];

    public function __construct(
        private AttachmentStorageInterface $storage,
    ) {
    }

    public function resolve(User $user, int $size = 64): AvatarRender
    {
        $alt = $user->getDisplayName();
        $source = $user->getAvatarSource();

        // 1. Upload local
        if (
            ($source === AvatarSource::LOCAL || $source === AvatarSource::AUTO)
            && $user->getAvatarPath() !== null
        ) {
            return AvatarRender::fromUrl($this->storage->publicUrl($user->getAvatarPath()), $alt, $size);
        }

        // 2. Authentik (cache local)
        if (
            ($source === AvatarSource::AUTHENTIK || $source === AvatarSource::AUTO)
            && $user->getAuthentikAvatarPath() !== null
        ) {
            return AvatarRender::fromUrl($this->storage->publicUrl($user->getAuthentikAvatarPath()), $alt, $size);
        }

        // 3. Gravatar
        if (
            $source !== AvatarSource::INITIALS
            && ($source === AvatarSource::GRAVATAR || $source === AvatarSource::AUTO)
            && $user->isGravatarAllowed()
            && $user->getEmail() !== ''
        ) {
            return AvatarRender::fromUrl(self::gravatarUrl($user->getEmail(), $size), $alt, $size);
        }

        // 4. Fallback : initiales SVG
        return AvatarRender::fromSvg($this->initialsSvg($user, $size), $alt, $size);
    }

    /**
     * Construit l'URL Gravatar pour un e-mail. Hash SHA-256 (recommandé par
     * la nouvelle API Gravatar, MD5 dépréciée) et `?d=identicon` pour avoir
     * toujours une image (motif géométrique unique par e-mail) plutôt qu'un
     * 404 cassé si l'utilisateur n'a pas de compte Gravatar.
     */
    public static function gravatarUrl(string $email, int $size): string
    {
        $hash = hash('sha256', strtolower(trim($email)));

        return self::GRAVATAR_BASE . $hash . '?d=identicon&s=' . $size;
    }

    /**
     * Génère un SVG inline avec les initiales du `displayName` sur fond
     * coloré stable (couleur dérivée de `authentikId`).
     */
    private function initialsSvg(User $user, int $size): string
    {
        $initials = $this->initialsOf($user->getDisplayName());
        $bg = $this->colorFor($user->getAuthentikId());
        $fontSize = (int) round($size * 0.42);

        // Note : on génère un SVG explicite pour éviter toute dépendance JS.
        // height=$size, width=$size, fond rond via cx/cy/r, texte centré.
        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$size} {$size}" width="{$size}" height="{$size}" role="img" aria-hidden="true">
              <circle cx="{$size}" cy="{$size}" r="{$size}" fill="{$bg}" transform="scale(0.5)"/>
              <text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" font-size="{$fontSize}" font-weight="600" fill="#ffffff">{$initials}</text>
            </svg>
            SVG;
    }

    private function initialsOf(string $displayName): string
    {
        $name = trim($displayName);
        if ($name === '') {
            return '?';
        }

        // 2 premières lettres des 2 premiers mots, ou 2 premières lettres du
        // mot unique. Casse forcée en majuscule.
        $words = preg_split('/\s+/u', $name) ?: [];
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    private function colorFor(string $authentikId): string
    {
        $index = crc32($authentikId) % count(self::INITIALS_PALETTE);

        return self::INITIALS_PALETTE[$index];
    }
}
