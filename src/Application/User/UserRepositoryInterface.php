<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Domain\User;

/**
 * Port applicatif d'accès aux utilisateurs (projection Authentik).
 *
 * Implémenté par `App\Infrastructure\Repository\UserRepository`. Le
 * découplage permet aux services applicatifs et aux contrôleurs de ne
 * pas dépendre directement de Doctrine (cf. docs/qualite.md §3).
 */
interface UserRepositoryInterface
{
    public function findOneByAuthentikId(string $authentikId): ?User;

    public function countAll(): int;

    public function countActive(): int;

    /**
     * @return list<User>
     */
    public function search(UserFilter $filter): array;

    /**
     * @return list<string>
     */
    public function listKnownGroups(): array;
}
