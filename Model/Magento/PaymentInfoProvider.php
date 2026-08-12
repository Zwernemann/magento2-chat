<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Magento;

use Magento\Framework\ObjectManagerInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects available payment methods + saved Vault tokens for a customer.
 * The result is added to $customerData['payment_methods'] in MessageProcessor
 * so ContextBuilder can render a "=== VERFÜGBARE ZAHLARTEN ===" block.
 *
 * Vault support is loaded via ObjectManager and silently skipped if the
 * Magento_Vault module is not present (Magento Open Source installations).
 */
class PaymentInfoProvider
{
    /** Payment method codes that are never useful to show the LLM */
    private const SKIP_METHODS = [
        'free', 'substitution', 'googlepay', 'braintree_googlepay',
        'braintree_applepay', 'paypal_express_bml', 'hosted_pro',
    ];

    public function __construct(
        private readonly PaymentConfig        $paymentConfig,
        private readonly ObjectManagerInterface $objectManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger
    ) {}

    /**
     * Returns a list of payment options available for this customer.
     *
     * Each entry has:
     *   type    'method' | 'vault'
     *   code    Payment method code or vault public hash
     *   label   Human-readable label
     *   expires (vault only) 'MM/YYYY' expiry string
     *
     * @return array<int, array{type: string, code: string, label: string, expires?: string}>
     */
    public function getForCustomer(int $customerId, int $storeId = 0): array
    {
        $result = [];

        // --- Active store payment methods ---
        try {
            $storeCode = $storeId > 0
                ? $this->storeManager->getStore($storeId)->getCode()
                : null;

            foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
                if (in_array($code, self::SKIP_METHODS, true)) {
                    continue;
                }
                // Skip vault renderer (the tokens appear separately below)
                if (str_starts_with($code, 'vault')) {
                    continue;
                }

                try {
                    $available = $method->isAvailable();
                } catch (\Throwable) {
                    $available = true; // assume available if check fails
                }
                if (!$available) {
                    continue;
                }

                $title = (string)($method->getConfigData('title') ?: $code);
                $result[] = [
                    'type'  => 'method',
                    'code'  => $code,
                    'label' => $title,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PaymentInfoProvider] Could not load active methods: ' . $e->getMessage());
        }

        // --- Saved Vault tokens (optional — Magento_Vault may not be installed) ---
        if ($customerId > 0) {
            try {
                /** @var \Magento\Vault\Api\PaymentTokenManagementInterface $tokenMgmt */
                $tokenMgmt = $this->objectManager->get(
                    \Magento\Vault\Api\PaymentTokenManagementInterface::class
                );
                $tokens = $tokenMgmt->getListByCustomerId($customerId);

                foreach ($tokens as $token) {
                    if (!$token->getIsActive() || !$token->getIsVisible()) {
                        continue;
                    }

                    // Filter expired tokens
                    $expiresAt = $token->getExpiresAt();
                    if ($expiresAt && strtotime($expiresAt) < time()) {
                        continue;
                    }

                    $details = json_decode($token->getTokenDetails() ?? '{}', true) ?: [];
                    $label   = $this->buildVaultLabel($details, $token->getType());
                    $expires = $this->buildExpiryString($details, $expiresAt);

                    $entry = [
                        'type'  => 'vault',
                        'code'  => 'vault_' . substr($token->getPublicHash(), 0, 8),
                        'label' => $label,
                    ];
                    if ($expires) {
                        $entry['expires'] = $expires;
                    }
                    $result[] = $entry;
                }
            } catch (\Throwable $e) {
                // Vault module not installed or other issue — silently skip
                $this->logger->debug('[PaymentInfoProvider] Vault tokens unavailable: ' . $e->getMessage());
            }
        }

        return $result;
    }

    private function buildVaultLabel(array $details, string $tokenType): string
    {
        $type   = $details['type'] ?? $details['cc_type'] ?? $tokenType;
        $last4  = $details['maskedCC'] ?? $details['last4'] ?? '';
        $expiry = $details['expirationDate'] ?? '';

        if ($last4) {
            $last4 = ltrim((string)$last4, 'X*•');
            return sprintf('%s •••• %s', ucfirst($type), $last4);
        }

        return ucfirst($type) ?: 'Gespeicherte Karte';
    }

    private function buildExpiryString(array $details, ?string $expiresAt): string
    {
        // Prefer details['expirationDate'] which is typically 'MM/YYYY'
        if (!empty($details['expirationDate'])) {
            return (string)$details['expirationDate'];
        }

        // Fall back to the token's expiresAt timestamp
        if ($expiresAt) {
            $ts = strtotime($expiresAt);
            return $ts ? date('m/Y', $ts) : '';
        }

        return '';
    }
}
