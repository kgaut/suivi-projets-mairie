<?php

declare(strict_types=1);

namespace App\Application\Storage;

/**
 * Abstraction du stockage de pièces jointes (PJ, avatars, etc.).
 *
 * Anticipée pour la GED externe (cf. specs §3.5 et §6 hors scope) :
 * en v1 on a une implémentation `LocalAttachmentStorage` qui écrit dans
 * `var/uploads/`. Plus tard, on pourra brancher Nextcloud, Paperless ou
 * autre sans toucher au métier (Avatar, PJ Project/Task, etc.).
 *
 * Convention : les `path` retournés sont **opaques** (pour l'app, c'est
 * juste une string). Pour générer une URL publique, passer par `publicUrl()`.
 */
interface AttachmentStorageInterface
{
    /**
     * Stocke un blob (contenu binaire) sous le chemin demandé.
     *
     * @param string $path chemin relatif logique (ex. `users/abc-512.webp`)
     * @param string $contents contenu binaire à écrire
     */
    public function store(string $path, string $contents): void;

    /**
     * Supprime un fichier stocké. No-op si le path n'existe pas.
     */
    public function delete(string $path): void;

    /**
     * Indique si un fichier existe à ce path.
     */
    public function exists(string $path): bool;

    /**
     * URL publique pour accéder au fichier (servie par le serveur web).
     */
    public function publicUrl(string $path): string;
}
