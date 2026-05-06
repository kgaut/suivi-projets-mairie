<?php

declare(strict_types=1);

namespace App\Application\Event;

use DateTimeImmutable;

/**
 * Implémentation de base pour les événements auditables.
 *
 * Les sous-classes :
 *   - définissent leur `category()` et `action()` en `final` (constantes statiques)
 *   - peuvent enrichir le contexte via leur constructeur
 *
 * `occurredAt()` est figé à l'instanciation pour rester fidèle au moment
 * réel de l'événement même si l'event est dispatché plus tard.
 */
abstract class AbstractAuditableEvent implements AuditableEvent
{
    private readonly DateTimeImmutable $occurredAt;

    /**
     * @param array<string, scalar|array<mixed>|null> $context
     */
    public function __construct(
        private readonly ?string $subjectAuthentikId,
        private readonly array $context = [],
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    public function subjectAuthentikId(): ?string
    {
        return $this->subjectAuthentikId;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
