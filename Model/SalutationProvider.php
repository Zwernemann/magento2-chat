<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Zwernemann\Chat\Model\Config\Source\AddressForm;

/**
 * Produces a salutation and farewell for system/tool-generated e-mail replies.
 *
 * LLM responses already carry an opening and closing (driven by the system prompt),
 * but responses that come straight from a tool (product search, order history,
 * wishlist, …) replace the LLM text with a bare result. This provider wraps that
 * bare text in the same greeting/sign-off — using the same backend settings
 * (Ansprache + Shop-Name) — so every mail reads as a complete, consistent letter.
 */
class SalutationProvider
{
    private const XML_PATH_ADDRESS_FORM = 'zwernemann_chat/llm/address_form';
    private const XML_PATH_SHOP_NAME    = 'zwernemann_chat/llm/shop_name';
    private const XML_PATH_FROM_NAME    = 'zwernemann_chat/smtp/from_name';

    /** Default Magento gender option ids. */
    private const GENDER_MALE   = 1;
    private const GENDER_FEMALE = 2;

    public function __construct(
        private readonly ScopeConfigInterface $config
    ) {}

    /**
     * Wrap a plain-text body in greeting + farewell.
     *
     * @param array<string, mixed> $customerData
     */
    public function wrapText(string $body, array $customerData, int $storeId = 0): string
    {
        $greeting = $this->greeting($customerData, $storeId);
        $farewell = $this->farewell($storeId);
        return $greeting . "\n\n" . trim($body) . "\n\n" . $farewell;
    }

    /**
     * Wrap an HTML body fragment in greeting + farewell paragraphs.
     *
     * @param array<string, mixed> $customerData
     */
    public function wrapHtml(string $bodyHtml, array $customerData, int $storeId = 0): string
    {
        $greeting = $this->greeting($customerData, $storeId);
        $farewell = $this->farewell($storeId);
        return '<p>' . htmlspecialchars($greeting) . '</p>'
            . $bodyHtml
            . '<p>' . nl2br(htmlspecialchars($farewell)) . '</p>';
    }

    /** @param array<string, mixed> $customerData */
    public function greeting(array $customerData, int $storeId = 0): string
    {
        $lastname  = trim((string)($customerData['lastname'] ?? ''));
        $firstname = trim((string)($customerData['firstname'] ?? ''));

        if ($this->isInformal($storeId)) {
            return $firstname !== '' ? 'Hallo ' . $firstname . ',' : 'Hallo,';
        }

        // Formal: only use "Herr/Frau <Nachname>" when the gender is unambiguous,
        // otherwise fall back to the always-correct neutral "Guten Tag,".
        $gender = (int)($customerData['gender'] ?? 0);
        if ($lastname !== '' && $gender === self::GENDER_MALE) {
            return 'Sehr geehrter Herr ' . $lastname . ',';
        }
        if ($lastname !== '' && $gender === self::GENDER_FEMALE) {
            return 'Sehr geehrte Frau ' . $lastname . ',';
        }
        return 'Guten Tag,';
    }

    public function farewell(int $storeId = 0): string
    {
        $closing = $this->isInformal($storeId) ? 'Viele Grüße' : 'Mit freundlichen Grüßen';
        return $closing . "\n" . $this->shopName($storeId);
    }

    private function isInformal(int $storeId): bool
    {
        $form = (string)$this->config->getValue(
            self::XML_PATH_ADDRESS_FORM,
            $storeId > 0 ? ScopeInterface::SCOPE_STORE : 'default',
            $storeId > 0 ? $storeId : null
        );
        return $form === AddressForm::INFORMAL;
    }

    private function shopName(int $storeId): string
    {
        $scope = $storeId > 0 ? ScopeInterface::SCOPE_STORE : 'default';
        $code  = $storeId > 0 ? $storeId : null;

        $name = trim((string)$this->config->getValue(self::XML_PATH_SHOP_NAME, $scope, $code));
        if ($name === '') {
            $name = trim((string)$this->config->getValue(self::XML_PATH_FROM_NAME, $scope, $code));
        }
        return $name !== '' ? $name : 'Ihr Serviceteam';
    }
}
