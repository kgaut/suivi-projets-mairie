<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Application\ExternalLink\ExternalLinkRepositoryInterface;
use App\Domain\ExternalLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalLink>
 */
class ExternalLinkRepository extends ServiceEntityRepository implements ExternalLinkRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalLink::class);
    }

    /**
     * Tous les liens triés par position ASC (pour l'écran admin).
     *
     * @return list<ExternalLink>
     */
    public function findAllOrdered(): array
    {
        /** @var list<ExternalLink> $result */
        $result = $this->createQueryBuilder('l')
            ->orderBy('l.position', 'ASC')
            ->addOrderBy('l.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Liens actifs triés (pour le lanceur d'apps côté utilisateur).
     *
     * @return list<ExternalLink>
     */
    public function findActiveOrdered(): array
    {
        /** @var list<ExternalLink> $result */
        $result = $this->createQueryBuilder('l')
            ->andWhere('l.enabled = true')
            ->orderBy('l.position', 'ASC')
            ->addOrderBy('l.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ExternalLink $link): void
    {
        $em = $this->getEntityManager();
        $em->persist($link);
        $em->flush();
    }

    public function remove(ExternalLink $link): void
    {
        $em = $this->getEntityManager();
        $em->remove($link);
        $em->flush();
    }
}
