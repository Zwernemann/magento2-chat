<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Shop;

/**
 * Shop-system-agnostic interface for customer lookup.
 * Implement this interface in a new Model/<Platform>/ namespace to support a different shop.
 */
interface CustomerProviderInterface
{
    /**
     * Find a customer by their primary or alias email address.
     *
     * @return array<string, mixed>|null  Keys: id, email, firstname, lastname, group_id, company, addresses
     */
    public function findByEmail(string $email): ?array;

    /**
     * Find a customer by telephone number (normalised, stripped of leading zeros and country prefix).
     *
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array;
}
