<?php

declare(strict_types=1);

namespace App\Application\Event;

use DateTimeImmutable;

/**
 * Marqueur pour les événements applicatifs candidats à l'audit log.
 *
 * Les classes implémentant cette interface sont dispatchées via
 * Symfony EventDispatcher et seront persistées au Lot 2 (table `audit_events`).
 *
 * Cf. docs/specifications.md §3.10 pour le périmètre fonctionnel et
 * docs/lots/lot-0-cadrage.md §3 (Vague 4) pour la liste des classes prévues.
 */
interface AuditableEvent
{
    /**
     * Catégorie de l'événement (`security`, `user`, `project`, `task`, etc.).
     * Utilisée pour l'indexation côté audit log et les filtres UI.
     */
    public function category(): string;

    /**
     * Action courte (`login.success`, `login.failed`, `access.denied`, etc.).
     * Concaténée avec la catégorie pour former la clé d'événement
     * (`security.login.success` par ex.).
     */
    public function action(): string;

    /**
     * Identifiant Authentik du sujet de l'événement (l'utilisateur concerné).
     * `null` si l'événement ne concerne pas un utilisateur (rare en v1).
     */
    public function subjectAuthentikId(): ?string;

    /**
     * Métadonnées libres associées à l'événement (ip, user-agent, raison
     * d'un refus d'accès…). Filtrées RGPD au moment de la persistance
     * (cf. specs §3.10).
     *
     * @return array<string, scalar|array<mixed>|null>
     */
    public function context(): array;

    public function occurredAt(): DateTimeImmutable;
}
