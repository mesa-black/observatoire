<?php

namespace App\Repository;

use App\Entity\Subscriber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscriber> */
class SubscriberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscriber::class);
    }

    /** @return array<string, int> status => count */
    public function countByStatus(): array
    {
        $counts = [Subscriber::PENDING => 0, Subscriber::CONFIRMED => 0];
        foreach ($this->createQueryBuilder('s')->select('s.status, COUNT(s.id) AS n')->groupBy('s.status')->getQuery()->getArrayResult() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<Subscriber> for the admin: one status, the most recent request first */
    public function withStatus(string $status): array
    {
        return $this->findBy(['status' => $status], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return iterable<Subscriber> */
    public function confirmed(): iterable
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')->setParameter('status', Subscriber::CONFIRMED)
            ->orderBy('s.id')
            ->getQuery()->toIterable();
    }

    /** Requests never confirmed are deleted after a week: an address nobody vouched for is not kept. */
    public function deletePendingBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')->delete()
            ->where('s.status = :status')->andWhere('s.requestedAt < :before')
            ->setParameter('status', Subscriber::PENDING)->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
