<?php

declare(strict_types=1);

namespace App\Application\ExternalLink;

use App\Domain\ExternalLink;

/**
 * Port applicatif d'accès aux liens externes du lanceur d'apps.
 *
 * Implémenté par `App\Infrastructure\Repository\ExternalLinkRepository`.
 */
interface ExternalLinkRepositoryInterface
{
    /**
     * Tous les liens triés par position ASC (vue admin).
     *
     * @return list<ExternalLink>
     */
    public function findAllOrdered(): array;

    /**
     * Liens actifs triés (lanceur d'apps).
     *
     * @return list<ExternalLink>
     */
    public function findActiveOrdered(): array;

    public function countAll(): int;

    public function save(ExternalLink $link): void;

    public function remove(ExternalLink $link): void;
}
