<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD operations for cc_customer_alias_email.
 * Keeps all DB access for alias lookups in one place.
 */
class CustomerAliasEmail
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function lookupCustomerIdByEmail(string $email): ?int
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('cc_customer_alias_email');
        $row   = $conn->fetchRow(
            'SELECT customer_id FROM ' . $table . ' WHERE email = ?',
            [strtolower(trim($email))]
        );
        return $row ? (int)$row['customer_id'] : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAll(): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('cc_customer_alias_email');
        return $conn->fetchAll(
            'SELECT a.*, c.email AS main_email, c.firstname, c.lastname
             FROM ' . $table . ' a
             LEFT JOIN ' . $this->resource->getTableName('customer_entity') . ' c
               ON c.entity_id = a.customer_id
             ORDER BY a.customer_id, a.email'
        );
    }

    public function add(int $customerId, string $email, string $label = ''): void
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('cc_customer_alias_email');
        $conn->insertOnDuplicate($table, [
            'customer_id' => $customerId,
            'email'       => strtolower(trim($email)),
            'label'       => $label,
        ], ['customer_id', 'label']);
    }

    public function delete(int $id): void
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('cc_customer_alias_email');
        $conn->delete($table, ['id = ?' => $id]);
    }
}
