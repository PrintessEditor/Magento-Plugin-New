<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Printess\PrintessEditor\Model\SaveTokenStatus;
use Psr\Log\LoggerInterface;

/**
 * Checks, before an admin reproduction changes anything, that the designs (save tokens) it reuses still exist at
 * Printess (finding f22 / D11). Ordered designs expire after the account's save-token lifetime (Printess default:
 * 30 days); a Restart or Duplicate after that could never be produced, so it is refused up front instead of
 * resetting the jobs (Restart) or creating a copy order (Duplicate) that then fails.
 *
 * Three-way like everywhere else: only a definite "inactive" from /shop/template/info blocks. "Unknown" (Printess
 * unreachable, no service token) lets the action go ahead; JobSubmitter checks again before sending (B7).
 */
class DesignAvailabilityCheck
{
    public function __construct(
        private readonly PrintessApiProvider $apiProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<array{label: string, token: string}> $designs one entry per item (label: SKU or "#<item id>")
     * @param string $action what is refused, for the message, e.g. "restarted" or "copied"
     * @throws LocalizedException when at least one design is definitely gone
     */
    public function assertAvailable(?int $storeId, array $designs, string $action): void
    {
        $states = [];
        foreach ($designs as $i => $design) {
            $states[$i] = $this->state($storeId, (string) $design['token']);
        }
        $message = self::refusalMessage($designs, $states, $action);
        if ($message !== null) {
            throw new LocalizedException(__($message));
        }
    }

    /**
     * Pure: the refusal text for the designs whose state is INACTIVE, or null when none is.
     *
     * @param list<array{label: string, token: string}> $designs
     * @param array<int, string> $states index of $designs => SaveTokenStatus::*
     */
    public static function refusalMessage(array $designs, array $states, string $action): ?string
    {
        $gone = [];
        foreach ($designs as $i => $design) {
            if (($states[$i] ?? null) === SaveTokenStatus::INACTIVE) {
                $gone[] = (string) $design['label'];
            }
        }
        if ($gone === []) {
            return null;
        }
        $gone = array_values(array_unique($gone));

        return sprintf(
            'Nothing was %s: the design of item %s no longer exists at Printess. Ordered designs are kept only for '
            . 'the save-token lifetime of your Printess account (30 days by default), so it cannot be produced again. '
            . 'The customer has to design the item anew.',
            $action,
            implode(', ', $gone)
        );
    }

    private function state(?int $storeId, string $token): string
    {
        if (trim($token) === '') {
            return SaveTokenStatus::INACTIVE;
        }
        try {
            $state = $this->apiProvider->forStore($storeId)->getSaveTokenState($token);
        } catch (\Throwable $e) {
            $state = SaveTokenStatus::UNKNOWN;
        }
        if ($state === SaveTokenStatus::UNKNOWN) {
            $this->logger->warning('Printess: design check before reproduction inconclusive, going ahead', [
                'store_id' => $storeId,
            ]);
        }
        return $state;
    }
}
