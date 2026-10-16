<?php

namespace App\Repository;

use App\Entity\Passkey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Passkey> */
final class PasskeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Passkey::class);
    }

    public function findOneByWebauthnId(string $id): ?Passkey
    {
        return $this->findOneBy(['webauthnId' => $id]);
    }

    /** @return list<Passkey> */
    public function all(): array
    {
        return $this->findBy([], ['createdAt' => 'ASC']);
    }
}
