<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Wrapper around the Pinecone REST API (Serverless).
 * Handles upsert and query operations on the product index.
 */
class PineconeClient
{
    private const XML_PATH_KEY    = 'zwernemann_chat/pinecone/api_key';
    private const XML_PATH_INDEX  = 'zwernemann_chat/pinecone/index_name';
    private const XML_PATH_TOP_K  = 'zwernemann_chat/pinecone/top_k';

    private ?string $indexHost = null;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly Curl                 $curl,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor,
        private readonly PipelineLogger       $pipelineLogger
    ) {}

    /**
     * Upsert vectors into the index.
     *
     * @param array<int, array{id: string, values: float[], metadata: array<string, mixed>}> $vectors
     * @param string $namespace  Pinecone namespace (e.g. "store_de"). Empty = global namespace.
     */
    public function upsert(array $vectors, string $namespace = ''): void
    {
        $url  = $this->getIndexHost() . '/vectors/upsert';
        $data = ['vectors' => $vectors];
        if ($namespace !== '') {
            $data['namespace'] = $namespace;
        }
        $this->request('POST', $url, json_encode($data));
    }

    /**
     * Query the index for similar vectors.
     *
     * @param  float[]  $vector     Query embedding
     * @param  int|null $topK       Override default top_k
     * @param  array<string, mixed> $filter  Optional metadata filter
     * @param  string   $namespace  Pinecone namespace (e.g. "store_de"). Empty = global namespace.
     * @return array<int, array{id: string, score: float, metadata: array<string, mixed>}>
     */
    public function query(array $vector, ?int $topK = null, array $filter = [], string $namespace = ''): array
    {
        $k       = $topK ?? (int)($this->config->getValue(self::XML_PATH_TOP_K) ?? 10);
        $payload = ['vector' => $vector, 'topK' => $k, 'includeMetadata' => true];
        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }
        if ($namespace !== '') {
            $payload['namespace'] = $namespace;
        }

        $this->pipelineLogger->section('PINECONE VECTOR QUERY');
        $this->pipelineLogger->data('TopK', $k);
        $this->pipelineLogger->data('Namespace', $namespace !== '' ? $namespace : '(global)');
        $this->pipelineLogger->data('Filter', empty($filter) ? '(none)' : $filter);
        $dims = count($vector);
        $this->pipelineLogger->data(
            'Query vector (' . $dims . ' dims)',
            sprintf('[%s … %s]',
                implode(', ', array_map(fn($v) => round($v, 6), array_slice($vector, 0, 4))),
                implode(', ', array_map(fn($v) => round($v, 6), array_slice($vector, -3)))
            )
        );

        $url      = $this->getIndexHost() . '/query';
        $response = $this->request('POST', $url, json_encode($payload));
        $matches  = $response['matches'] ?? [];

        $this->pipelineLogger->data('Matches returned', count($matches));
        $this->pipelineLogger->data('Full match list (id + score + metadata)', $matches);

        return $matches;
    }

    /**
     * Delete vectors by IDs.
     *
     * @param string[] $ids
     */
    public function delete(array $ids): void
    {
        $url     = $this->getIndexHost() . '/vectors/delete';
        $payload = json_encode(['ids' => $ids]);
        $this->request('POST', $url, $payload);
    }

    /**
     * Delete ALL vectors in a namespace (used before force re-index to remove stale entries).
     * Guard: never operates on the global default namespace (empty string).
     * A 404 response means the namespace does not exist yet — treated as success.
     */
    public function deleteNamespace(string $namespace): void
    {
        if ($namespace === '') {
            return;
        }
        $apiKey  = $this->getApiKey();
        $url     = $this->getIndexHost() . '/vectors/delete';
        $payload = json_encode(['deleteAll' => true, 'namespace' => $namespace]);

        $this->curl->setHeaders(['Api-Key' => $apiKey, 'Content-Type' => 'application/json']);
        $this->curl->post($url, $payload);
        $status = $this->curl->getStatus();

        if ($status === 404) {
            // Namespace does not exist yet — nothing to clear, proceed with indexing
            return;
        }

        if ($status >= 400) {
            $this->logger->error(sprintf(
                'Zwernemann_Chat: Pinecone deleteNamespace HTTP %d – %s',
                $status, $this->curl->getBody()
            ));
            throw new \RuntimeException('Pinecone deleteNamespace failed (' . $status . ')');
        }

        $this->logger->info(sprintf(
            'Zwernemann_Chat: Pinecone namespace "%s" cleared (deleteAll).', $namespace
        ));
    }

    private function getIndexHost(): string
    {
        if ($this->indexHost !== null) {
            return $this->indexHost;
        }

        $apiKey    = $this->getApiKey();
        $indexName = $this->config->getValue(self::XML_PATH_INDEX) ?? 'magento-products';

        // Describe index to get host
        $this->curl->setHeaders([
            'Api-Key'      => $apiKey,
            'Content-Type' => 'application/json',
        ]);
        $this->curl->get('https://api.pinecone.io/indexes/' . urlencode($indexName));
        $body     = $this->curl->getBody();
        $response = json_decode($body, true);

        if (!isset($response['host'])) {
            // Index does not exist yet – create it
            $this->createIndex($apiKey, $indexName);
            // Re-fetch host
            $this->curl->setHeaders(['Api-Key' => $apiKey, 'Content-Type' => 'application/json']);
            $this->curl->get('https://api.pinecone.io/indexes/' . urlencode($indexName));
            $response = json_decode($this->curl->getBody(), true);
        }

        $this->indexHost = 'https://' . ($response['host'] ?? '');
        return $this->indexHost;
    }

    private function createIndex(string $apiKey, string $indexName): void
    {
        $region  = $this->config->getValue('zwernemann_chat/pinecone/region') ?? 'us-east-1';
        $payload = json_encode([
            'name'      => $indexName,
            'dimension' => 1024,
            'metric'    => 'cosine',
            'spec'      => ['serverless' => ['cloud' => 'aws', 'region' => $region]],
        ]);

        $this->curl->setHeaders(['Api-Key' => $apiKey, 'Content-Type' => 'application/json']);
        $this->curl->post('https://api.pinecone.io/indexes', $payload);
        $this->logger->info('Zwernemann_Chat: Pinecone index created: ' . $indexName);

        // Wait for index to be ready (max 60s)
        $start = time();
        while (time() - $start < 60) {
            sleep(2);
            $this->curl->setHeaders(['Api-Key' => $apiKey, 'Content-Type' => 'application/json']);
            $this->curl->get('https://api.pinecone.io/indexes/' . urlencode($indexName));
            $status = json_decode($this->curl->getBody(), true);
            if (($status['status']['ready'] ?? false) === true) {
                break;
            }
        }
    }

    private function request(string $method, string $url, string $payload): array
    {
        $apiKey = $this->getApiKey();
        $this->curl->setHeaders([
            'Api-Key'      => $apiKey,
            'Content-Type' => 'application/json',
        ]);

        if ($method === 'POST') {
            $this->curl->post($url, $payload);
        } else {
            $this->curl->get($url);
        }

        $status   = $this->curl->getStatus();
        $body     = $this->curl->getBody();
        $response = json_decode($body, true);

        if ($status >= 400) {
            $this->logger->error(sprintf(
                'Zwernemann_Chat: Pinecone HTTP %d – %s',
                $status, $body
            ));
            throw new \RuntimeException('Pinecone request failed (' . $status . '): ' . $body);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Zwernemann_Chat: Pinecone invalid response – ' . $body);
            return [];
        }

        return $response ?? [];
    }

    private function getApiKey(): string
    {
        $apiKey = trim($this->encryptor->decrypt($this->config->getValue(self::XML_PATH_KEY) ?? ''));
        if (empty($apiKey)) {
            throw new \RuntimeException('Zwernemann_Chat: Pinecone API key not configured.');
        }
        return $apiKey;
    }
}
