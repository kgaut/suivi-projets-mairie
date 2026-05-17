<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Application\User\UserFilter;
use App\Application\User\UserRepositoryInterface;
use App\Domain\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface
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

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les utilisateurs non désactivés (`disabledAt IS NULL`).
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.disabledAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recherche utilisateurs pour la liste admin avec filtres.
     *
     * - `$filter->search` matche `username`, `email` ou `displayName` (case-insensitive, en SQL)
     * - `$filter->status` filtre actif/désactivé (en SQL)
     * - `$filter->role` et `$filter->group` filtrent sur des colonnes JSON
     *   (rôles applicatifs, snapshot des groupes Authentik) : DQL n'a pas
     *   d'opérateur JSON portable, on filtre donc en PHP après hydratation.
     *   Acceptable tant que la base reste de l'ordre de la centaine d'users
     *   (cas mairie). Si on a un jour besoin d'une vraie liste paginée, on
     *   bascule sur une requête native PG `jsonb @> ARRAY[...]`.
     *
     * Tri : `displayName` ASC.
     *
     * @return list<User>
     */
    public function search(UserFilter $filter): array
    {
        $qb = $this->createQueryBuilder('u');

        if ($filter->search !== null && $filter->search !== '') {
            $qb->andWhere('LOWER(u.username) LIKE :term OR LOWER(u.email) LIKE :term OR LOWER(u.displayName) LIKE :term')
                ->setParameter('term', '%' . mb_strtolower($filter->search) . '%');
        }

        if ($filter->status === UserFilter::STATUS_ACTIVE) {
            $qb->andWhere('u.disabledAt IS NULL');
        } elseif ($filter->status === UserFilter::STATUS_DISABLED) {
            $qb->andWhere('u.disabledAt IS NOT NULL');
        }

        $qb->orderBy('u.displayName', 'ASC');

        /** @var list<User> $users */
        $users = $qb->getQuery()->getResult();

        if ($filter->role !== null && $filter->role !== '') {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => in_array($filter->role, $user->getRoles(), true),
            ));
        }

        if ($filter->group !== null && $filter->group !== '') {
            return array_values(array_filter(
                $users,
                static fn (User $user): bool => in_array($filter->group, $user->getGroupsSnapshot(), true),
            ));
        }

        return $users;
    }

    /**
     * Liste des groupes Authentik distincts vus dans les `groupsSnapshot` des
     * utilisateurs. Sert au filtre admin. Implémentation simple (charge tous
     * les snapshots et dédoublonne en PHP — acceptable tant que la base
     * d'users reste de l'ordre de la centaine, ce qui est le cas en mairie).
     *
     * @return list<string>
     */
    public function listKnownGroups(): array
    {
        $qb = $this->createQueryBuilder('u')->select('u.groupsSnapshot');
        /** @var list<array{groupsSnapshot: list<string>}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $groups = [];
        foreach ($rows as $row) {
            foreach ($row['groupsSnapshot'] as $group) {
                $groups[$group] = true;
            }
        }

        $names = array_keys($groups);
        sort($names);

        return $names;
    }
}
