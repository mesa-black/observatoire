<?php

namespace App\Repository;

use App\Entity\Newsletter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Newsletter> */
class NewsletterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Newsletter::class);
    }

    /** @return list<Newsletter> newest first */
    public function history(): array
    {
        return $this->findBy([], ['id' => 'DESC']);
    }

    /** @return list<Newsletter> */
    public function due(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.status = :status')->andWhere('n.scheduledAt <= :now')
            ->setParameter('status', Newsletter::SCHEDULED)->setParameter('now', $now)
            ->orderBy('n.scheduledAt')
            ->getQuery()->getResult();
    }
}
