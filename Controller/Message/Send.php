<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Controller\Message;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\MessageProcessorInterface;
use Zwernemann\Chat\Model\Message\UnifiedMessageFactory;
use Zwernemann\Chat\Model\PipelineLogger;

class Send implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'docx', 'doc'];
    private const MAX_FILE_SIZE      = 20 * 1024 * 1024; // 20 MB per file

    public function __construct(
        private readonly RequestInterface          $request,
        private readonly JsonFactory               $jsonFactory,
        private readonly FormKeyValidator          $formKeyValidator,
        private readonly CustomerSession           $customerSession,
        private readonly ScopeConfigInterface      $scopeConfig,
        private readonly MessageProcessorInterface $messageProcessor,
        private readonly UnifiedMessageFactory     $messageFactory,
        private readonly StoreManagerInterface     $storeManager,
        private readonly LoggerInterface           $logger,
        private readonly PipelineLogger            $pipelineLogger
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setData(['error' => 'Invalid form key'])->setHttpResponseCode(403);
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['error' => 'Not authenticated'])->setHttpResponseCode(401);
        }

        if (!$this->scopeConfig->isSetFlag(
            'zwernemann_chat/webchat/enabled',
            ScopeInterface::SCOPE_STORE
        )) {
            return $result->setData(['error' => 'WebChat is disabled'])->setHttpResponseCode(403);
        }

        $messageText = trim((string)$this->request->getParam('message', ''));
        $sessionId   = trim((string)$this->request->getParam('session_id', ''));

        if ($messageText === '') {
            return $result->setData(['error' => 'Empty message'])->setHttpResponseCode(400);
        }

        $customer  = $this->customerSession->getCustomer();
        $email     = $customer->getEmail();
        $sessionId = ($sessionId !== '') ? $sessionId : ('webchat_' . uniqid('', true));

        $attachments = $this->processUploadedFiles();

        $message = $this->messageFactory->create();
        $message
            ->setChannelType('webchat')
            ->setMessageId('webchat_' . uniqid('', true))
            ->setSessionId($sessionId)
            ->setCustomerIdentifier($email)
            ->setContentText($messageText)
            ->setAttachments($attachments)
            ->setTimestamp(date('Y-m-d H:i:s'))
            ->setStoreId((int)$this->storeManager->getStore()->getId());

        try {
            $processResult = $this->messageProcessor->process($message);
        } catch (\Throwable $e) {
            $this->logger->error('Zwernemann_Chat WebChat: ' . $e->getMessage());
            return $result->setData(['error' => 'An error occurred. Please try again.'])->setHttpResponseCode(500);
        }

        $responseText = $processResult['text'] ?? '';
        $responseHtml = $processResult['html'] ?? '';

        if ($responseText === 'unauthorized') {
            return $result->setData(['error' => 'Your account is not authorized for this service.'])->setHttpResponseCode(403);
        }

        $debugBlocks = $this->pipelineLogger->getDebugBlocks();

        return $result->setData([
            'response'      => $responseText,
            'response_html' => $responseHtml,
            'session_id'    => $sessionId,
            'debug_blocks'  => $debugBlocks,
        ]);
    }

    private function processUploadedFiles(): array
    {
        $attachments = [];

        // Use $_FILES directly — Magento's RequestInterface::getFiles() does not reliably
        // expose multipart file uploads; $_FILES is always correct for PHP HTTP uploads.
        $filesRaw = $_FILES['attachments'] ?? null;

        if (empty($filesRaw) || empty($filesRaw['name'])) {
            return $attachments;
        }

        // Normalize to list of individual file arrays
        $files = $this->normalizeFiles($filesRaw);

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->logger->debug('CC WebChat: file upload error code ' . ($file['error'] ?? -1), ['name' => $file['name'] ?? '']);
                continue;
            }

            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $this->logger->debug('CC WebChat: rejected file extension', ['ext' => $ext]);
                continue;
            }

            $tmpName = (string)($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $this->logger->debug('CC WebChat: not an uploaded file', ['tmp_name' => $tmpName]);
                continue;
            }

            if (filesize($tmpName) > self::MAX_FILE_SIZE) {
                $this->logger->warning('CC WebChat: file too large', ['name' => $file['name'] ?? '', 'size' => filesize($tmpName)]);
                continue;
            }

            $content = file_get_contents($tmpName);
            if ($content === false) {
                continue;
            }

            $attachments[] = [
                'filename'     => basename((string)($file['name'] ?? 'upload.' . $ext)),
                'content_type' => (string)($file['type'] ?? 'application/octet-stream'),
                'data'         => base64_encode($content),
            ];
        }

        $this->logger->info('CC WebChat: processed attachments', ['count' => count($attachments)]);
        return $attachments;
    }

    /**
     * Normalize $_FILES array for multiple uploads into a flat list.
     * PHP's multi-file upload format is `['name' => [...], 'tmp_name' => [...], ...]`
     * whereas a single file is `['name' => 'file.pdf', 'tmp_name' => '/tmp/xxx', ...]`.
     */
    private function normalizeFiles(array $filesRaw): array
    {
        if (!is_array($filesRaw['name'] ?? '')) {
            // Single file
            return [$filesRaw];
        }

        $files = [];
        $keys  = array_keys($filesRaw);
        $count = count($filesRaw['name']);

        for ($i = 0; $i < $count; $i++) {
            $file = [];
            foreach ($keys as $key) {
                $file[$key] = $filesRaw[$key][$i] ?? null;
            }
            $files[] = $file;
        }

        return $files;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // We perform CSRF validation manually in execute() via FormKeyValidator
        return true;
    }
}
