<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Attachment;

use Psr\Log\LoggerInterface;

/**
 * Converts raw email attachment arrays from MailPoller into ExtractedAttachment objects.
 *
 * Format strategy:
 *   PDF           → Anthropic document block (base64 passed through unchanged)
 *   XLSX / DOCX   → ZipArchive extracts raw XML → embedded as prompt text
 *   .xls / .doc   → blockType='warning' so the LLM can inform the customer
 *   Everything else → null + log warning (silently skipped)
 *
 * No third-party libraries are required: ZipArchive is a PHP built-in extension
 * and PDF data from MailPoller is already base64-encoded.
 */
class AttachmentProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Process all raw attachments from MailPoller.
     *
     * @param array<int, array{filename: string, content_type: string, data: string}> $rawAttachments
     * @return ExtractedAttachment[]
     */
    public function processAll(array $rawAttachments): array
    {
        $result = [];
        foreach ($rawAttachments as $attachment) {
            $extracted = $this->processSingle($attachment);
            if ($extracted !== null) {
                $result[] = $extracted;
            }
        }
        return $result;
    }

    /** @param array{filename: string, content_type: string, data: string} $attachment */
    private function processSingle(array $attachment): ?ExtractedAttachment
    {
        $filename    = $attachment['filename']     ?? '';
        $contentType = strtolower($attachment['content_type'] ?? '');
        $data        = $attachment['data']         ?? '';   // base64 from MailPoller

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // PDF — native Anthropic document block; base64 data is passed through as-is
        if ($ext === 'pdf' || $contentType === 'application/pdf') {
            return new ExtractedAttachment($filename, 'document', $data, 'application/pdf');
        }

        // OOXML (ZIP-based) formats — extract raw XML for LLM interpretation
        if ($ext === 'xlsx' || str_contains($contentType, 'spreadsheetml')) {
            return $this->extractOoxml($filename, $data, 'xlsx');
        }
        if ($ext === 'docx' || str_contains($contentType, 'wordprocessingml')) {
            return $this->extractOoxml($filename, $data, 'docx');
        }

        // Legacy binary formats — not parseable without external libraries;
        // return a warning block so the LLM can tell the customer
        if ($ext === 'xls'
            || $ext === 'doc'
            || $contentType === 'application/vnd.ms-excel'
            || $contentType === 'application/msword'
        ) {
            $this->logger->warning(
                'Zwernemann_Chat: Legacy binary attachment "' . $filename . '" ('
                . $contentType . ') cannot be parsed. Customer should resave as .xlsx/.docx/.pdf.'
            );
            $note = 'Der Anhang "' . $filename . '" verwendet ein nicht unterstütztes Legacy-Format '
                . '(' . pathinfo($filename, PATHINFO_EXTENSION) . '). '
                . 'Bitte den Kunden bitten, die Datei als .xlsx, .docx oder .pdf zu speichern '
                . 'und erneut zu senden.';
            return new ExtractedAttachment($filename, 'warning', $note);
        }

        $this->logger->warning(
            'Zwernemann_Chat: Unsupported attachment "' . $filename
            . '" (content-type: ' . $contentType . '). Skipping.'
        );
        return null;
    }

    private function extractOoxml(string $filename, string $base64Data, string $type): ?ExtractedAttachment
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cc_att_');
        if ($tmpFile === false) {
            $this->logger->error(
                'Zwernemann_Chat: Could not create temp file for attachment "' . $filename . '".'
            );
            return null;
        }

        try {
            file_put_contents($tmpFile, base64_decode($base64Data, true));

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                $this->logger->warning(
                    'Zwernemann_Chat: Could not open ZIP container for attachment "' . $filename . '".'
                );
                return null;
            }

            if ($type === 'xlsx') {
                $sharedStrings = $zip->getFromName('xl/sharedStrings.xml') ?: '';
                $sheet1        = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
                $xmlContent    = $sharedStrings . "\n" . $sheet1;
            } else {
                $xmlContent = $zip->getFromName('word/document.xml') ?: '';
            }
            $zip->close();

            if (trim($xmlContent) === '') {
                $this->logger->warning(
                    'Zwernemann_Chat: No XML content extracted from "' . $filename . '".'
                );
                return null;
            }

            return new ExtractedAttachment($filename, 'text', $xmlContent);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Zwernemann_Chat: Exception extracting "' . $filename . '": ' . $e->getMessage()
            );
            return null;
        } finally {
            @unlink($tmpFile);
        }
    }
}
