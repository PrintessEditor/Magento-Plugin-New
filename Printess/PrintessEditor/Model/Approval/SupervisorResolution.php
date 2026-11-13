<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Approval;

/**
 * Picks the Default Order Supervisor: product value → store config → null ("notify all supervisors of the website").
 * A candidate is skipped when $isValid says it lost the role, was deleted or belongs to another website.
 *
 * PURE.
 */
final class SupervisorResolution
{
    /**
     * @param callable(int):bool $isValid
     */
    public function resolve(?int $productSupervisorId, ?int $storeSupervisorId, callable $isValid): ?int
    {
        foreach ([$productSupervisorId, $storeSupervisorId] as $candidate) {
            if ($candidate !== null && $candidate > 0 && $isValid($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
