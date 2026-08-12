<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Attachment;

/**
 * Immutable value object representing one processed email attachment.
 *
 * blockType values:
 *   'document' – PDF sent as a native Anthropic document block (base64 in content, mediaType set)
 *   'text'     – DOCX/XLSX XML extracted via ZipArchive, embedded inline in the prompt
 *   'warning'  – Unsupported legacy format; content is a human-readable note for the LLM
 */
class ExtractedAttachment
{
    public function __construct(
        private readonly string $filename,
        private readonly string $blockType,
        private readonly string $content,
        private readonly string $mediaType = ''
    ) {}

    public function getFilename(): string  { return $this->filename;  }
    public function getBlockType(): string { return $this->blockType; }
    public function getContent(): string   { return $this->content;   }
    public function getMediaType(): string { return $this->mediaType; }

    /**
     * Returns plain text suitable for use as a RAG search query supplement.
     * XLSX/DOCX: strip XML tags. PDF: none — the compressed binary yields no
     * meaningful text and must never feed keyword search or embeddings; the
     * LLM reads PDFs natively as document blocks instead.
     */
    public function toSearchText(): string
    {
        if ($this->blockType === 'text') {
            return trim(strip_tags($this->content));
        }
        return '';
    }
}
