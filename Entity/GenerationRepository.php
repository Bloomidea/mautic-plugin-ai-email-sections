<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<Generation>
 */
class GenerationRepository extends CommonRepository
{
    /**
     * Basis for the rate limit. Mautic 7 does not ship symfony/rate-limiter, and
     * counting here is free because telemetry already writes a row per generation.
     */
    public function countSince(int $userId, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.createdBy = :user')
            ->andWhere('g.createdAt >= :since')
            ->setParameter('user', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * What the purge command's dry run reports. Counting and deleting are kept
     * apart so a dry run cannot delete by accident.
     */
    public function countOlderThan(\DateTimeInterface $cutoff): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Backs the cleanup command. Retention is configurable and defaults to
     * 180 days.
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        return (int) $this->createQueryBuilder('g')
            ->delete()
            ->where('g.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
