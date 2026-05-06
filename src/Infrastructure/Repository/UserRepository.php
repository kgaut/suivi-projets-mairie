<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Réconciliation au login : on retrouve un User par son `sub` Authentik
     * (clé stable). Cette méthode est utilisée par le provider OIDC à chaque
     * authentification.
     */
    public function findOneByAuthentikId(string $authentikId): ?User
    {
        return $this->findOneBy(['authentikId' => $authentikId]);
    }
}
