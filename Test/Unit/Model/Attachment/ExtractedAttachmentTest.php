<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Attachment;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Attachment\ExtractedAttachment;

/**
 * toSearchText() must never leak PDF binary into RAG queries: compressed PDF
 * streams used to be ASCII-mined into garbage like "%PDF-1.4 ReportLab …" and
 * fed to keyword search and embeddings.
 */
class ExtractedAttachmentTest extends TestCase
{
    public function testDocumentBlockYieldsNoSearchText(): void
    {
        $pdfBinary  = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nstream\n\x01\x02\x03ReportLab\nendstream";
        $attachment = new ExtractedAttachment(
            'Angebot_2026-0457.pdf',
            'document',
            base64_encode($pdfBinary),
            'application/pdf'
        );

        $this->assertSame('', $attachment->toSearchText());
    }

    public function testTextBlockStripsXmlTags(): void
    {
        $attachment = new ExtractedAttachment(
            'bestellung.xlsx',
            'text',
            '<row><c>10</c><c>Basecap grün</c></row>'
        );

        $this->assertSame('10Basecap grün', $attachment->toSearchText());
    }

    public function testWarningBlockYieldsNoSearchText(): void
    {
        $attachment = new ExtractedAttachment(
            'alt.xls',
            'warning',
            'Bitte als XLSX erneut senden.'
        );

        $this->assertSame('', $attachment->toSearchText());
    }
}
