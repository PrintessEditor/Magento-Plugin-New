<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\CustomerFields;

use Magento\Framework\App\ResourceConnection;

/**
 * printess_customer_form_field: admin-set Printess form field values per customer and store view (RMC-01).
 */
class Repository
{
    private const TABLE = 'printess_customer_form_field';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** @return array<string,string> field name => value for one customer in one store view */
    public function getValues(int $customerId, int $storeId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['field_name', 'value'])
            ->where('customer_id = ?', $customerId)
            ->where('store_id = ?', $storeId);
        return array_map('strval', $connection->fetchPairs($select));
    }

    /** @return array<int,array<string,string>> store id => [field name => value] */
    public function getAllForCustomer(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['store_id', 'field_name', 'value'])
            ->where('customer_id = ?', $customerId);
        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['store_id']][(string) $row['field_name']] = (string) $row['value'];
        }
        return $out;
    }

    /**
     * Replaces the customer's values for the given stores and field. Empty values are deleted.
     *
     * @param array<int,string> $valuesByStore store id => normalized value
     */
    public function saveForCustomer(int $customerId, string $field, array $valuesByStore): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            foreach ($valuesByStore as $storeId => $value) {
                if ($value === '') {
                    $connection->delete($table, [
                        'customer_id = ?' => $customerId,
                        'store_id = ?'    => (int) $storeId,
                        'field_name = ?'  => $field,
                    ]);
                    continue;
                }
                $connection->insertOnDuplicate($table, [
                    'customer_id' => $customerId,
                    'store_id'    => (int) $storeId,
                    'field_name'  => $field,
                    'value'       => $value,
                ], ['value']);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
