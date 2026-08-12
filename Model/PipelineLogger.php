<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

/**
 * Per-request file logger for complete pipeline traceability.
 *
 * Shared singleton via Magento DI. The pipeline entry points
 * (MessageProcessor, InboundProcessor) call startRequest() and finishRequest().
 * Every other class in the pipeline calls section() / data() / raw() — these
 * are silent no-ops when no request is active (e.g. during CLI indexing).
 *
 * Files: <magento-root>/var/log/cc_pipeline/YYYY-MM-DD_HHmmss_<session>.log
 */
class PipelineLogger
{
    private ?string $filePath     = null;
    private bool    $debugCapture = false;
    /** @var array<int, array{title: string, request: array, response: string}> */
    private array   $debugBlocks  = [];

    public function enableDebugCapture(bool $enable): void
    {
        $this->debugCapture = $enable;
        $this->debugBlocks  = [];
    }

    /**
     * Capture a full LLM request+response pair for WebChat debug output.
     * No-op when debug capture is not enabled.
     *
     * @param array<string, mixed> $request  Full JSON payload sent to the API (no API key)
     */
    public function addDebugBlock(string $title, array $request, string $rawResponse): void
    {
        if (!$this->debugCapture) {
            return;
        }
        $this->debugBlocks[] = [
            'title'    => $title,
            'request'  => $request,
            'response' => $rawResponse,
        ];
    }

    /** @return array<int, array{title: string, request: array, response: string}> */
    public function getDebugBlocks(): array
    {
        return $this->debugBlocks;
    }

    public function startRequest(string $sessionId, string $messageId, string $channel): void
    {
        $dir = BP . '/var/log/cc_pipeline/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir,0777);
        }

        $safe           = preg_replace('/[^a-zA-Z0-9@._-]/', '-', $sessionId);
        $this->filePath = $dir . date('Y-m-d_His') . '_' . substr($safe, 0, 60) . '.log';

        $hr = str_repeat('═', 70);
        $this->write(
            $hr . "\n" .
            "  CONVERSIONAL COMMERCE — COMPLETE PIPELINE TRACE\n" .
            $hr . "\n" .
            "Time:      " . date('Y-m-d H:i:s T') . "\n" .
            "Session:   " . $sessionId . "\n" .
            "MessageID: " . $messageId . "\n" .
            "Channel:   " . $channel . "\n" .
            $hr . "\n\n"
        );
    }

    public function active(): bool
    {
        return $this->filePath !== null;
    }

    /** Write a section header (visual separator with timestamp) */
    public function section(string $title): void
    {
        if (!$this->active()) {
            return;
        }
        $hr = str_repeat('─', 70);
        $this->write("\n" . $hr . "\n[" . date('H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000)
            . "]  " . strtoupper($title) . "\n" . $hr . "\n");
    }

    /**
     * Log a labeled value.
     * Arrays/objects → pretty-printed JSON. Strings → raw. Booleans → true/false.
     */
    public function data(string $label, mixed $value): void
    {
        if (!$this->active()) {
            return;
        }
        if (is_array($value) || is_object($value)) {
            $text = json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } elseif (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $text = 'null';
        } else {
            $text = (string)$value;
        }
        $this->write($label . ":\n" . $text . "\n\n");
    }

    /**
     * Log a raw multi-line text block (e.g. a full prompt, an HTTP response body).
     * Wraps in a visible border so boundaries are clear.
     */
    public function raw(string $label, string $text): void
    {
        if (!$this->active()) {
            return;
        }
        $border = str_repeat('·', 70);
        $this->write($label . ":\n" . $border . "\n" . $text . "\n" . $border . "\n\n");
    }

    public function finishRequest(int $durationMs = 0): void
    {
        if (!$this->active()) {
            return;
        }
        $hr = str_repeat('═', 70);
        $this->write(
            "\n" . $hr . "\n" .
            "  PIPELINE END — " . date('H:i:s') .
            ($durationMs ? "  —  total {$durationMs} ms" : '') . "\n" .
            $hr . "\n"
        );
        $this->debugCapture = false;
        $this->filePath     = null;
    }

    private function write(string $text): void
    {
        if ($this->filePath === null) {
            return;
        }
        file_put_contents($this->filePath, $text, FILE_APPEND | LOCK_EX);
    }
}
