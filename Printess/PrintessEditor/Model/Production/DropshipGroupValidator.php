<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. Checks a dropship group before anything is sent: a group is sent completely or not at all, because
 * Printess waits for every linked item (`linkedOrderLineItems`) before it hands the order to the dropshipper.
 *
 * Input per job: job_id, order_item_id, save_token, product_definition_id (0 = "from the Printess template" is
 * valid; null/'' is not), and optionally label (the item's SKU, used in the messages).
 */
class DropshipGroupValidator
{
    public const CODE_ITEM_MISSING   = 'item_missing';
    public const CODE_NO_SAVE_TOKEN  = 'no_save_token';
    public const CODE_NO_DEFINITION  = 'no_product_definition';
    public const CODE_NO_ADDRESS     = 'no_address';
    public const CODE_DESIGN_GONE    = 'design_not_found';
    public const CODE_GROUP_INVALID  = 'group_invalid';

    /**
     * @param list<array{job_id?: mixed, order_item_id?: mixed, save_token?: mixed, product_definition_id?: mixed}> $jobs
     * @param array<int|string> $existingOrderItemIds ids of the order's items that still exist
     * @return list<array{job_id: int, order_item_id: int, code: string, message: string}> empty when the group is valid
     */
    public function validate(array $jobs, array $existingOrderItemIds, bool $hasAddress): array
    {
        $existing = array_flip(array_map('intval', $existingOrderItemIds));
        $problems = [];
        $labels   = $this->labels($jobs);

        if (!$hasAddress) {
            $problems[] = [
                'job_id'        => 0,
                'order_item_id' => 0,
                'code'          => self::CODE_NO_ADDRESS,
                'message'       => 'The order has neither a shipping nor a billing address to dropship to',
            ];
        }

        foreach ($jobs as $job) {
            $jobId  = (int) ($job['job_id'] ?? 0);
            $itemId = (int) ($job['order_item_id'] ?? 0);

            if (!isset($existing[$itemId])) {
                $problems[] = $this->problem($jobId, $itemId, self::CODE_ITEM_MISSING, 'the order item no longer exists', $labels[$jobId] ?? '');
                continue;
            }
            $token = $job['save_token'] ?? null;
            if (!is_scalar($token) || trim((string) $token) === '') {
                $problems[] = $this->problem($jobId, $itemId, self::CODE_NO_SAVE_TOKEN, 'no save token (design) is stored', $labels[$jobId] ?? '');
            }
            $definition = $job['product_definition_id'] ?? null;
            if ($definition === null || $definition === '' || !is_numeric($definition) || (int) $definition < 0) {
                $problems[] = $this->problem($jobId, $itemId, self::CODE_NO_DEFINITION, 'no dropship product definition is set', $labels[$jobId] ?? '');
            }
        }

        return $problems;
    }

    /**
     * One error code and message for the whole group (every job of it is failed with them).
     *
     * @param list<array{job_id: int, order_item_id: int, code: string, message: string}> $problems non-empty
     * @return array{code: string, message: string}
     */
    public static function summarize(array $problems): array
    {
        $codes = array_values(array_unique(array_column($problems, 'code')));
        $parts = [];
        foreach ($problems as $problem) {
            $label = is_string($problem['label'] ?? null) && $problem['label'] !== '' ? $problem['label'] : null;
            $parts[] = $label !== null
                ? sprintf('item %s: %s', $label, $problem['message'])
                : ($problem['order_item_id'] > 0
                    ? sprintf('item #%d: %s', $problem['order_item_id'], $problem['message'])
                    : $problem['message']);
        }

        return [
            'code'    => count($codes) === 1 ? $codes[0] : self::CODE_GROUP_INVALID,
            'message' => 'The dropship group was not sent (nothing reached Printess). ' . implode('; ', $parts) . '.',
        ];
    }

    /**
     * Second pass, after validate() passed: the designs as Printess knows them. $states maps job_id to the save
     * token's state from /shop/template/info (SaveTokenStatus::ACTIVE / INACTIVE / UNKNOWN). Only a definite
     * INACTIVE (not found, deleted or expired) blocks the group; UNKNOWN (check failed) does not, so a Printess
     * hiccup never stops production on its own.
     *
     * @param list<array{job_id?: mixed, order_item_id?: mixed, label?: mixed}> $jobs
     * @param array<int, string> $states
     * @return list<array{job_id: int, order_item_id: int, code: string, message: string, label?: string}>
     */
    public function tokenProblems(array $jobs, array $states): array
    {
        $problems = [];
        foreach ($jobs as $job) {
            $jobId = (int) ($job['job_id'] ?? 0);
            if (($states[$jobId] ?? null) === \Printess\PrintessEditor\Model\SaveTokenStatus::INACTIVE) {
                $problems[] = $this->problem(
                    $jobId,
                    (int) ($job['order_item_id'] ?? 0),
                    self::CODE_DESIGN_GONE,
                    'the design (save token) does not exist at Printess anymore or has expired',
                    is_scalar($job['label'] ?? null) ? trim((string) $job['label']) : ''
                );
            }
        }
        return $problems;
    }

    /** @return array<int, string> job_id => label (SKU) when given */
    private function labels(array $jobs): array
    {
        $labels = [];
        foreach ($jobs as $job) {
            if (is_scalar($job['label'] ?? null) && trim((string) $job['label']) !== '') {
                $labels[(int) ($job['job_id'] ?? 0)] = trim((string) $job['label']);
            }
        }
        return $labels;
    }

    private function problem(int $jobId, int $itemId, string $code, string $message, string $label = ''): array
    {
        $problem = ['job_id' => $jobId, 'order_item_id' => $itemId, 'code' => $code, 'message' => $message];
        if ($label !== '') {
            $problem['label'] = $label;
        }
        return $problem;
    }
}
