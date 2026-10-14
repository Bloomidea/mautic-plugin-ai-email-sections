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
     * reading it here is free because telemetry already writes a row per
     * generation.
     *
     * Returns when the $limit-th most recent generation since $since was made,
     * or null when there are fewer than $limit. A user who has one is at the
     * limit, and stays there until that generation leaves the window: the
     * oldest one when exactly at the limit, a later one when a lowered limit
     * left them above it.
     */
    public function findLimitingCreatedAt(int $userId, \DateTimeInterface $since, int $limit): ?\DateTimeInterface
    {
        $row = $this->createQueryBuilder('g')
            ->select('g.createdAt')
            ->where('g.createdBy = :user')
            ->andWhere('g.createdAt >= :since')
            ->setParameter('user', $userId)
            ->setParameter('since', $since)
            ->orderBy('g.createdAt', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->setFirstResult($limit - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $createdAt = $row['createdAt'] ?? null;

        return $createdAt instanceof \DateTimeInterface ? $createdAt : null;
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
