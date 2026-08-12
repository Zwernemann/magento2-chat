<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Magento;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\ResourceModel\Address\CollectionFactory as AddressCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\Shop\CustomerProviderInterface;
use Zwernemann\Chat\Model\PipelineLogger;
use Zwernemann\Chat\Model\ResourceModel\CustomerAliasEmail;

/**
 * Finds a Magento customer by email using the native CustomerRepository.
 * Falls back to the cc_customer_alias_email table so that customers can
 * communicate from secondary email addresses.
 */
class CustomerLookup implements CustomerProviderInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerAliasEmail          $aliasResource,
        private readonly AddressCollectionFactory    $addressCollectionFactory,
        private readonly LoggerInterface             $logger,
        private readonly PipelineLogger              $pipelineLogger
    ) {}

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $this->pipelineLogger->section('CUSTOMER LOOKUP (by email)');
        $this->pipelineLogger->raw('Input identifier', $email);

        // 1. Direct Magento customer lookup by primary email
        try {
            $result = $this->buildData($this->customerRepository->get($email));
            $this->pipelineLogger->data('Found via primary email', $result);
            return $result;
        } catch (NoSuchEntityException) {
            $this->pipelineLogger->data('Primary email', 'not found — checking alias table');
        }

        // 2. Check alias table
        $customerId = $this->aliasResource->lookupCustomerIdByEmail($email);
        if ($customerId !== null) {
            try {
                $result = $this->buildData($this->customerRepository->getById($customerId));
                $this->pipelineLogger->data('Found via alias table (customer_id=' . $customerId . ')', $result);
                return $result;
            } catch (NoSuchEntityException) {
                $this->logger->warning(
                    'Zwernemann_Chat: Alias email ' . $email
                    . ' maps to customer_id=' . $customerId . ' which no longer exists.'
                );
            }
        }

        $this->pipelineLogger->data('Customer lookup result', 'NOT FOUND — request will be rejected');
        $this->logger->info('Zwernemann_Chat: No customer found for email: ' . $email);
        return null;
    }

    /**
     * Find a customer by their phone number (stored in Magento address telephone field).
     * Normalises the number by stripping spaces, dashes and leading zeros/+ before comparing.
     *
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        $normalised = $this->normalisePhone($phone);
        $this->pipelineLogger->section('CUSTOMER LOOKUP (by phone)');
        $this->pipelineLogger->raw('Input phone', $phone);
        $this->pipelineLogger->data('Normalised (digits, stripped leading zeros)', $normalised);

        if ($normalised === '') {
            $this->pipelineLogger->data('Result', 'empty after normalisation — rejected');
            return null;
        }

        try {
            // Query customer_address_entity directly — customerRepository->getList()
            // cannot filter on address fields (telephone lives in a separate table).
            $collection = $this->addressCollectionFactory->create();
            $collection->addAttributeToFilter('telephone', ['like' => '%' . $normalised]);

            foreach ($collection as $address) {
                if ($this->normalisePhone((string)$address->getTelephone()) !== $normalised) {
                    continue; // exact normalised comparison to skip LIKE false-positives
                }
                $customerId = (int)$address->getParentId();
                if (!$customerId) {
                    continue;
                }
                try {
                    $result = $this->buildData($this->customerRepository->getById($customerId));
                    $this->pipelineLogger->data('Found via address telephone (customer_id=' . $customerId . ')', $result);
                    return $result;
                } catch (NoSuchEntityException) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Zwernemann_Chat: Phone lookup failed – ' . $e->getMessage());
        }

        $this->pipelineLogger->data('Customer lookup result', 'NOT FOUND — request will be rejected');
        $this->logger->info('Zwernemann_Chat: No customer found for phone: ' . $phone);
        return null;
    }

    private function normalisePhone(string $phone): string
    {
        // Remove everything except digits
        $digits = preg_replace('/\D/', '', $phone);
        // Strip leading country-code zeros (00 prefix → remove; + was already stripped)
        return ltrim((string)$digits, '0');
    }

    /** @return array<string, mixed> */
    private function buildData(CustomerInterface $customer): array
    {
        $addresses = [];
        $company   = '';

        foreach ($customer->getAddresses() ?? [] as $addr) {
            $addrCompany = $addr->getCompany() ?? '';
            if ($addrCompany !== '') {
                // Prefer company from the default billing address; fall back to any address with one set
                if ($company === '' || (string)$customer->getDefaultBilling() === (string)$addr->getId()) {
                    $company = $addrCompany;
                }
            }
            $addresses[] = [
                'id'               => $addr->getId(),
                'firstname'        => $addr->getFirstname(),
                'lastname'         => $addr->getLastname(),
                'street'           => $addr->getStreet(),
                'city'             => $addr->getCity(),
                'postcode'         => $addr->getPostcode(),
                'country_id'       => $addr->getCountryId(),
                'telephone'        => $addr->getTelephone() ?? '',
                'default_billing'  => (string)$customer->getDefaultBilling() === (string)$addr->getId(),
                'default_shipping' => (string)$customer->getDefaultShipping() === (string)$addr->getId(),
            ];
        }

        return [
            'id'         => (int)$customer->getId(),
            'email'      => $customer->getEmail(),
            'firstname'  => $customer->getFirstname(),
            'lastname'   => $customer->getLastname(),
            'gender'     => (int)$customer->getGender(),
            'group_id'   => (int)$customer->getGroupId(),
            'company'    => $company,
            'website_id' => (int)$customer->getWebsiteId(),
            'addresses'  => $addresses,
        ];
    }
}
