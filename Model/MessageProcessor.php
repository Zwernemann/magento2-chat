<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\Conversation\TopicResolver;
use Zwernemann\Chat\Model\PipelineLogger;
use Zwernemann\Chat\Api\Data\ConversationInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;
use Zwernemann\Chat\Api\MessageProcessorInterface;
use Zwernemann\Chat\Api\ChannelInterface;
use Zwernemann\Chat\Api\EscalationHandlerInterface;
use Zwernemann\Chat\Api\ErrorNotifierInterface;
use Zwernemann\Chat\Api\Shop\CartServiceInterface;
use Zwernemann\Chat\Api\Shop\CustomerProviderInterface;
use Zwernemann\Chat\Api\Shop\OrderHistoryInterface;
use Zwernemann\Chat\Api\Shop\ProductLookupInterface;
use Zwernemann\Chat\Model\Attachment\AttachmentProcessor;
use Zwernemann\Chat\Model\Llm\ContextBuilder;
use Zwernemann\Chat\Model\Llm\IntentProcessor;
use Zwernemann\Chat\Model\Magento\PaymentInfoProvider;
use Zwernemann\Chat\Model\Pipeline\CartActionHandler;
use Zwernemann\Chat\Model\Pipeline\MagentoToolExecutor;
use Zwernemann\Chat\Model\Pipeline\OrderConfirmationFormatter;
use Zwernemann\Chat\Model\Rag\ConversationalQueryBuilder;
use Zwernemann\Chat\Model\Rag\ProductIndexer;
use Zwernemann\Chat\Model\ResourceModel\RejectedMessage;

/**
 * Central orchestrator for incoming messages.
 *
 * Processing pipeline:
 * 1. Resolve customer (Magento REST)
 * 2. Load order history (Magento REST)
 * 3. Semantic product search (Pinecone + Voyage RAG)
 * 4. LLM intent detection + response generation (Anthropic Claude)
 * 5a. Create order if intent = order/reorder (Magento REST)
 * 5b. Ask clarification if intent = clarification
 * 6. Send response email with inline product images
 * 7. Persist conversation + messages in DB
 */
class MessageProcessor implements MessageProcessorInterface
{
    /** @var ChannelInterface[] */
    private readonly array $channels;

    public function __construct(
        private readonly CustomerProviderInterface $customerLookup,
        private readonly OrderHistoryInterface     $orderHistory,
        private readonly ProductIndexer            $productIndexer,
        private readonly ProductLookupInterface    $productSearch,
        private readonly ContextBuilder            $contextBuilder,
        private readonly IntentProcessor           $intentProcessor,
        private readonly AttachmentProcessor       $attachmentProcessor,
        private readonly CartServiceInterface      $cartManager,
        private readonly CartActionHandler         $cartActionHandler,
        private readonly MagentoToolExecutor       $toolExecutor,
        private readonly PaymentInfoProvider       $paymentInfoProvider,
        private readonly ChannelInterface          $defaultChannel,
        private readonly ConversationFactory        $conversationFactory,
        private readonly ConversationMessageFactory $messageFactory,
        private readonly ResourceModel\Conversation $conversationResource,
        private readonly ResourceModel\ConversationMessage $messageResource,
        private readonly ConversationalQueryBuilder $queryBuilder,
        private readonly TopicResolver         $topicResolver,
        private readonly DateTime              $dateTime,
        private readonly EscalationHandlerInterface $escalationHandler,
        private readonly ErrorNotifierInterface     $errorNotifier,
        private readonly RejectedMessage       $rejectedMessage,
        private readonly SalutationProvider    $salutationProvider,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface      $logger,
        private readonly PipelineLogger       $pipelineLogger,
        array $channels = []
    ) {
        $this->channels = $channels;
    }

    public function process(UnifiedMessageInterface $message): array
    {
        $sid   = $message->getSessionId();
        $start = microtime(true);

        $this->pipelineLogger->enableDebugCapture(
            $message->getChannelType() === 'webchat'
            && $this->scopeConfig->isSetFlag(
                'zwernemann_chat/webchat/debug_llm',
                ScopeInterface::SCOPE_STORE
            )
        );
        $this->pipelineLogger->startRequest($sid, $message->getMessageId(), $message->getChannelType());
        $this->pipelineLogger->section('INBOUND MESSAGE');
        $this->pipelineLogger->data('Metadata', [
            'channel'    => $message->getChannelType(),
            'from'       => $message->getCustomerIdentifier(),
            'session_id' => $sid,
            'message_id' => $message->getMessageId(),
        ]);
        $this->pipelineLogger->raw('Message body (full)', $message->getContentText());

        $this->logger->info('=== PIPELINE START ===', [
            'session'       => $sid,
            'channel'       => $message->getChannelType(),
            'from'          => $message->getCustomerIdentifier(),
            'subject'       => $message->getReplyTo()['subject'] ?? '',
            'body_chars'    => strlen($message->getContentText()),
            'body_preview'  => mb_substr($message->getContentText(), 0, 300),
        ]);

        // Step 1: Resolve customer — reject unregistered senders immediately
        $customerData = $this->resolveCustomer($message);
        if ($customerData === null) {
            $this->logger->warning('[STEP 1] Auth rejected — sender not a registered customer', [
                'session' => $sid,
                'email'   => $message->getCustomerIdentifier(),
            ]);
            // Send the rejection notice exactly once per inbound mail. Rejected senders get
            // no conversation/message row, so without this guard the IMAP poller would
            // re-fetch the same mail every cycle and re-send the rejection every minute.
            $messageId = $message->getMessageId();
            if ($this->rejectedMessage->exists($messageId)) {
                $this->logger->info('[STEP 1] Rejection already sent for this message — skipping resend', [
                    'session'    => $sid,
                    'message_id' => $messageId,
                ]);
                return 'unauthorized';
            }
            $this->rejectedMessage->record($messageId, $message->getCustomerIdentifier());
            $this->sendUnauthorizedReply($message);
            return 'unauthorized';
        }

        $this->logger->info('[STEP 1] Customer resolved', [
            'session'     => $sid,
            'customer_id' => $customerData['id'],
            'name'        => ($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? ''),
            'addresses'   => count($customerData['addresses'] ?? []),
        ]);

        // Inbound text used for persistence and query classification: attachment-only
        // emails must never yield an empty message — the Anthropic API rejects empty
        // user turns and later turns would lose all trace of what the customer sent.
        $attachmentNote = $this->buildAttachmentPlaceholder($message->getAttachments());
        $inboundDbText  = trim(trim($message->getContentText())
            . ($attachmentNote !== '' ? "\n" . $attachmentNote : ''));

        // Step 1b: Auto-reply via RFC header — abort before LLM to prevent reply loops
        if ($message->isAutoReply()) {
            $this->logger->info('[STEP 1b] Auto-reply (header) detected — suppressing response to prevent loop', [
                'session' => $sid,
                'from'    => $message->getCustomerIdentifier(),
                'subject' => $message->getReplyTo()['subject'] ?? '',
            ]);
            $conversation = $this->getOrCreateConversation($message, $customerData);
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'content_text' => $inboundDbText,
                'intent'       => 'auto_reply',
            ]);
            return 'auto_reply';
        }

        // Step 2: Conversation session
        $conversation = $this->getOrCreateConversation($message, $customerData);
        $this->logger->info('[STEP 2] Conversation', [
            'session'         => $sid,
            'conversation_id' => $conversation->getId(),
            'status'          => $conversation->getStatus(),
        ]);

        // Step 2b: If the conversation is currently held for manual review, hold the
        // message and inform the customer — do not forward to the LLM. In the free
        // module the null escalation handler never holds; premium supplies the check.
        if ($this->escalationHandler->isOnHold($conversation)) {
            $this->logger->info('[STEP 2b] Conversation is escalated — holding inbound message', [
                'session'         => $sid,
                'conversation_id' => $conversation->getId(),
            ]);
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'content_text' => $inboundDbText,
                'intent'       => 'escalated_hold',
            ]);
            $holdText = "Vielen Dank für Ihre Nachricht.\n\n"
                . "Ihre Anfrage wird derzeit von unserem Team überprüft. "
                . "Sobald die Konversation freigegeben ist, erhalten Sie eine Antwort. "
                . "Bitte haben Sie noch etwas Geduld.";
            $holdHtml = '<p>Vielen Dank für Ihre Nachricht.</p>'
                . '<p>Ihre Anfrage wird derzeit von unserem Team überprüft. '
                . 'Sobald die Konversation freigegeben ist, erhalten Sie eine Antwort. '
                . 'Bitte haben Sie noch etwas Geduld.</p>';
            try {
                $channel = $this->channels[$message->getChannelType()] ?? $this->defaultChannel;
                $channel->sendResponse($message, $holdText, $holdHtml);
            } catch (\Throwable $e) {
                $this->logger->warning('[STEP 2b] Failed to send escalation hold reply – ' . $e->getMessage());
            }
            return ['text' => $holdText, 'html' => $holdHtml];
        }

        // Step 2c: For email/WhatsApp, the channel carries no store context.
        // Derive the store from the customer's "Associate to Website" setting.
        if ($message->getChannelType() !== 'webchat' && !empty($customerData['website_id'])) {
            try {
                $website    = $this->storeManager->getWebsite((int)$customerData['website_id']);
                $group      = $this->storeManager->getGroup($website->getDefaultGroupId());
                $resolvedId = (int)$group->getDefaultStoreId();
                if ($resolvedId > 0 && $resolvedId !== (int)$conversation->getStoreId()) {
                    $conversation->setStoreId($resolvedId);
                    $this->conversationResource->save($conversation);
                    $this->logger->info('[STEP 2c] Store resolved from customer website', [
                        'session'    => $sid,
                        'website_id' => $customerData['website_id'],
                        'store_id'   => $resolvedId,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[STEP 2c] Could not resolve store from customer website_id=' . ($customerData['website_id'] ?? 0));
            }
        }

        $storeId         = (int)$conversation->getStoreId();
        $originalStoreId = (int)$this->storeManager->getStore()->getId();
        if ($storeId > 0) {
            $this->storeManager->setCurrentStore($storeId);
        }

        try {
            // Step 3: Order history
            $orders = $this->orderHistory->getByCustomerEmail(
                $message->getCustomerIdentifier(), 20, $storeId
            );
            $this->logger->info('[STEP 3] Order history', [
                'session'     => $sid,
                'order_count' => count($orders),
                'last_order'  => $orders[0]['increment_id'] ?? null,
                'last_total'  => isset($orders[0]) ? $orders[0]['grand_total'] . ' EUR' : null,
                'last_status' => $orders[0]['status'] ?? null,
            ]);

            // Pre-process attachments BEFORE the RAG search so that SKUs/product names
            // embedded in Excel/PDF/Word files are included in the search query.
            // This fixes the case where the message body is just "möchte das bestellen"
            // while the actual product identifiers live inside the attachment.
            $attachments          = $message->getAttachments();
            $extractedAttachments = [];
            if (!empty($attachments)) {
                try {
                    $extractedAttachments = $this->attachmentProcessor->processAll($attachments);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 4] Attachment pre-extraction failed', [
                        'session' => $sid,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Build augmented search query: message body + plain text from each attachment
            // (XLSX/DOCX). PDFs are never parsed in PHP — they are passed to the term
            // extractor as native Anthropic document blocks so Haiku reads the line items.
            $attachmentTexts = array_filter(array_map(
                fn($a) => $a->toSearchText(),
                $extractedAttachments
            ));
            $documentBlocks = $this->contextBuilder->buildDocumentBlocks($extractedAttachments);
            $searchQuery = trim($message->getContentText()
                . (empty($attachmentTexts) ? '' : "\n" . implode("\n", $attachmentTexts)));

            // Use conversation-aware query builder to reformulate the search query.
            // For short continuation messages ("ja bitte", "10 stück") this resolves
            // the actual product from the recent conversation history.
            // Load recent history early (before step 5) specifically for this purpose.
            $recentHistoryForQuery = $this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), $this->queryBuilder->getHistoryLoadCount(), 'DESC',
                $conversation->getTopicStartedAt()
            );
            $queryBuilderResult  = $this->queryBuilder->build(
                $inboundDbText, $recentHistoryForQuery
            );
            $queryType           = $queryBuilderResult['query_type'];
            $needsRag            = $queryBuilderResult['needs_rag'];
            $conversationalQuery = $queryBuilderResult['query'];

            if ($needsRag && $conversationalQuery !== $inboundDbText) {
                $searchQuery = $conversationalQuery;
                if (!empty($attachmentTexts)) {
                    $searchQuery .= "\n" . implode("\n", $attachmentTexts);
                }
            }

            // Pass the resolved query to the LLM only when it differs from the raw message.
            $resolvedQueryForLlm = ($needsRag && $conversationalQuery !== $inboundDbText)
                ? $conversationalQuery
                : '';

            // Step 3c: If the previous turn was a clarification question, carry its context
            // (question + extracted order items) into this turn — a short reply like
            // "ja, bitte" answers that question and must not drift to older topics.
            $pendingClarification = $this->findPendingClarification($recentHistoryForQuery);

            // Step 3b-1: Detect a topic change. A clearly new, unrelated product request must
            // not inherit the previous topic's clarification context (which would pollute the
            // RAG search); on email/whatsapp it also opens a fresh conversation entry. Done
            // before the enrichment/prepend below so that on a topic change the old positions
            // are dropped entirely and the catalog search runs on the clean current query.
            // The semantic judgement (continuation vs. new topic) is made by the query-builder
            // LLM (continues_topic); the guards here are structural only: there must be prior
            // context, and account/address/general queries never start a new product topic.
            $isTopicChange = !empty($recentHistoryForQuery)
                && in_array($queryType, ['product', 'cart', 'reorder'], true)
                && !$queryBuilderResult['continues_topic'];
            if ($isTopicChange) {
                $conversation = $this->startNewTopic(
                    $conversation, $message, $conversationalQuery, $pendingClarification['items'] ?? []
                );
                $pendingClarification  = [];
                $recentHistoryForQuery = [];
                $this->logger->info('[STEP 3b-1] Topic change — context isolated', [
                    'session'         => $sid,
                    'conversation_id' => $conversation->getId(),
                    'channel'         => $message->getChannelType(),
                ]);
            } elseif ($conversation->getSubject() === '' && $queryType === 'product') {
                // Backfill a subject for the (possibly brand-new) conversation from the first
                // resolved product topic, so the grid shows a meaningful title.
                $subject = $this->topicResolver->deriveSubject(
                    $conversationalQuery, $pendingClarification['items'] ?? []
                );
                if ($subject !== '') {
                    $conversation->setSubject($subject);
                    $this->conversationResource->save($conversation);
                }
            }

            // Enrich pending positions with their configurable option schema so the main
            // model knows which need a variant choice (e.g. Farbe) — even when the item is
            // not in this turn's RAG window. Without this it omits options and the add fails.
            if (!empty($pendingClarification['items'])) {
                foreach ($pendingClarification['items'] as &$pendingItem) {
                    $pendingSku = trim((string)($pendingItem['sku'] ?? ''));
                    if ($pendingSku !== '') {
                        $pendingItem['options_text'] =
                            $this->productIndexer->getConfigurableOptionsTextBySku($pendingSku, $storeId);
                    }
                }
                unset($pendingItem);
            }
            if (!empty($pendingClarification['items'])) {
                $itemsQuery = implode("\n", array_map(
                    fn($i) => trim(($i['sku'] ?? '') . ' ' . ($i['name'] ?? '')),
                    $pendingClarification['items']
                ));
                $searchQuery = trim($itemsQuery . "\n" . $searchQuery);
                $needsRag    = true; // SKU matching needs catalog hits even for cart confirmations
            }
            if (!empty($pendingClarification)) {
                $this->pipelineLogger->section('PENDING CLARIFICATION (previous turn)');
                $this->pipelineLogger->data('Question', mb_substr($pendingClarification['question'] ?? '', 0, 500));
                $this->pipelineLogger->data('Extracted items', $pendingClarification['items'] ?? []);
            }

            // Step 4: Semantic product search — skip entirely for account/order/address queries
            $topK = max(1, (int)($this->scopeConfig->getValue('zwernemann_chat/pinecone/top_k') ?: 25));
            if (!empty($pendingClarification['items'])) {
                // A confirmed line-item list needs RAG headroom per item. One slot per
                // position is too tight: each item competes against several near-duplicate
                // catalog variants (e.g. the same switch in matt/gloss, or sibling Hager
                // breakers), so a borderline match ranks just outside a snug window and is
                // wrongly reported "not found". Budget ~2 slots per item plus a margin.
                $topK = max($topK, min(count($pendingClarification['items']) * 2 + 8, 50));
            }
            if (!empty($documentBlocks)) {
                // Attached order documents can carry many line items (16+); each needs
                // enough RAG slots to clear near-duplicate variants and map to a catalog SKU.
                $topK = max($topK, 40);
            }
            $ragResults = [];
            if ($needsRag && (trim($searchQuery) !== '' || !empty($documentBlocks))) {
                try {
                    $ragResults = $this->productIndexer->search($searchQuery, $topK, $storeId, $documentBlocks);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 4] RAG search failed', [
                        'session' => $sid,
                        'error'   => $e->getMessage(),
                    ]);
                    $this->errorNotifier->notify('RAG-Suchfehler (Pinecone/Voyage)', $e->getMessage(), $storeId);
                }
            }
            $this->logger->info('[STEP 4] RAG search', [
                'session'          => $sid,
                'query'            => !$needsRag
                    ? '(skipped — non-product query)'
                    : (trim($searchQuery) === '' && empty($documentBlocks)
                        ? '(skipped — empty query, no documents)'
                        : mb_substr($searchQuery, 0, 300) . (empty($documentBlocks) ? '' : ' [+' . count($documentBlocks) . ' document(s)]')),
                'attachment_texts' => count($attachmentTexts),
                'hits'             => count($ragResults),
                'top_results'      => array_map(fn($r) => [
                    'name'      => $r['metadata']['name'] ?? '?',
                    'sku'       => $r['metadata']['sku']  ?? '?',
                    'cats'      => $r['metadata']['categories'] ?? '',
                    'score'     => round((float)($r['score'] ?? 0), 4),
                    'has_image' => !empty($r['metadata']['image_url']),
                ], $ragResults),
            ]);

            // Step 4b: Enrich RAG results with live stock data from Magento
            if (!empty($ragResults)) {
                $skus = array_values(array_filter(array_map(
                    fn($r) => $r['metadata']['sku'] ?? '',
                    $ragResults
                )));
                if (!empty($skus)) {
                    $stockData = $this->productSearch->getStockForSkus($skus);
                    foreach ($ragResults as &$result) {
                        $sku = $result['metadata']['sku'] ?? '';
                        if ($sku && isset($stockData[$sku])) {
                            $sd = $stockData[$sku];
                            $result['metadata']['in_stock']     = $sd['in_stock'];
                            $result['metadata']['stock_qty']    = $sd['stock_qty'];
                            $result['metadata']['manage_stock'] = $sd['manage_stock'];
                            if (!empty($sd['variants'])) {
                                $result['metadata']['variants'] = $sd['variants'];
                            }
                        }
                    }
                    unset($result);
                    $this->logger->info('[STEP 4b] Stock enrichment', [
                        'session'      => $sid,
                        'skus_checked' => count($skus),
                        'configurable' => array_values(array_filter(array_map(
                            function ($sku) use ($stockData) {
                                $sd = $stockData[$sku] ?? null;
                                if (!$sd || empty($sd['variants'])) {
                                    return null;
                                }
                                return [
                                    'sku'            => $sku,
                                    'in_stock'       => $sd['in_stock'],
                                    'variants_found' => count($sd['variants']),
                                ];
                            },
                            $skus
                        ))),
                    ]);

                    // Step 4c: Enrich RAG results with customer-group-specific prices and tier prices
                    $customerGroupId = (int)($customerData['group_id'] ?? 0);
                    $priceData = $this->productSearch->getPriceDataForSkus($skus, $customerGroupId);
                    foreach ($ragResults as &$result) {
                        $sku = $result['metadata']['sku'] ?? '';
                        if ($sku && isset($priceData[$sku])) {
                            $pd = $priceData[$sku];
                            $result['metadata']['list_price'] = $pd['list_price'];
                            $result['metadata']['price']      = $pd['group_price'];
                            if (!empty($pd['tier_prices'])) {
                                $result['metadata']['tier_prices'] = $pd['tier_prices'];
                            }
                        }
                    }
                    unset($result);
                    $this->logger->info('[STEP 4c] Price enrichment', [
                        'session'        => $sid,
                        'customer_group' => $customerGroupId,
                        'skus_enriched'  => count($priceData),
                    ]);
                }
            }

            // Step 5: Conversation history — load newest-first, then reverse to chronological
            // DESC+reverse ensures sessions longer than 20 turns still provide the most recent context.
            $history = array_reverse($this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), 20, 'DESC', $conversation->getTopicStartedAt()
            ));
            $this->pipelineLogger->section('CONVERSATION HISTORY (last 20 messages)');
            $this->pipelineLogger->data('Messages (' . count($history) . ')', $history);
            $this->logger->info('[STEP 5] Conversation history', [
                'session'      => $sid,
                'message_count'=> count($history),
            ]);

            // Step 5b: Load active cart contents — added to customerData so ContextBuilder
            // can include them in the LLM prompt under "=== AKTUELLER WARENKORB ==="
            try {
                $cartContents = $this->cartManager->getCartContents((int)$customerData['id'], $storeId);
                $customerData['cart_items'] = !empty($cartContents) ? $cartContents : null;
            } catch (\Throwable $e) {
                $customerData['cart_items'] = null;
                $this->logger->warning('[STEP 5b] Cart lookup failed – ' . $e->getMessage());
            }
            $this->logger->info('[STEP 5b] Cart contents', [
                'session'      => $sid,
                'items_count'  => $customerData['cart_items']['items_count'] ?? 0,
                'subtotal'     => $customerData['cart_items']['subtotal'] ?? 0,
            ]);

            // Step 5c: Load available payment methods + saved Vault tokens for LLM context
            try {
                $customerData['payment_methods'] = $this->paymentInfoProvider->getForCustomer(
                    (int)$customerData['id'],
                    $storeId
                );
            } catch (\Throwable $e) {
                $customerData['payment_methods'] = [];
                $this->logger->warning('[STEP 5c] Payment methods lookup failed – ' . $e->getMessage());
            }

            // Step 6: LLM intent detection — pass pre-extracted attachments to avoid double-processing
            $this->logger->info('[STEP 6] LLM intent (attachments: ' . count($attachments) . ')', [
                'session'          => $sid,
                'attachment_files' => array_column($attachments, 'filename'),
            ]);
            $degraded = $this->productIndexer->isSearchDegraded()
                     || $this->queryBuilder->isDegraded();
            // Ask the LLM only for what this channel actually renders: HTML-only channels
            // (WebChat) skip the duplicated plaintext body; its plaintext is derived from HTML.
            $needsPlainText = ($this->channels[$message->getChannelType()] ?? $this->defaultChannel)
                ->needsPlainText();
            $llmResult = $this->intentProcessor->process(
                $message, $customerData, $orders, $ragResults, $history, $attachments, $extractedAttachments, $resolvedQueryForLlm, $degraded, $queryType, $pendingClarification, $needsPlainText
            );
            $this->logger->info('[STEP 6] LLM result', [
                'session'          => $sid,
                'intent'           => $llmResult['intent'] ?? '?',
                'confidence'       => $llmResult['confidence'] ?? 0,
                'tool_calls'       => array_column($llmResult['tool_calls'] ?? [], 'name'),
                'products_to_show' => $llmResult['product_ids_to_show'] ?? [],
                'response_preview' => mb_substr($llmResult['response_text'] ?? '', 0, 300),
            ]);

            // Step 6b: LLM classified this as an auto-reply — persist inbound, send nothing
            if (($llmResult['intent'] ?? '') === 'auto_reply') {
                $this->logger->info('[STEP 6b] Auto-reply (LLM) detected — suppressing response to prevent loop', [
                    'session' => $sid,
                    'from'    => $message->getCustomerIdentifier(),
                ]);
                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                    'content_text' => $inboundDbText,
                    'intent'       => 'auto_reply',
                ]);
                return 'auto_reply';
            }

            // Step 7: Persist inbound message
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'content_text' => $inboundDbText,
                'intent'       => $llmResult['intent'] ?? 'unknown',
            ]);

            // Step 7b: Escalation detection — count consecutive clarification turns in history.
            // Clarifications that carry extracted order items (document processing:
            // "here are your 16 positions — shall I order?") are productive steps,
            // not signs of confusion, and are excluded from the consecutive count.
            $consecutiveClarifications = 0;
            foreach (array_reverse($history) as $h) {
                if (($h['direction'] ?? '') !== ConversationMessage::DIRECTION_OUTBOUND
                    || ($h['intent'] ?? '') !== 'ask_clarification'
                ) {
                    break;
                }
                $intentData = json_decode((string)($h['intent_data'] ?? ''), true);
                if (is_array($intentData) && !empty($intentData['extracted_items'])) {
                    continue;
                }
                $consecutiveClarifications++;
            }
            $escalationReason = $this->escalationHandler->detect(
                $message,
                $llmResult,
                $ragResults,
                $consecutiveClarifications,
                $storeId
            );
            if ($escalationReason !== null) {
                $this->logger->warning('[STEP 7b] Escalation triggered', [
                    'session'         => $sid,
                    'conversation_id' => $conversation->getId(),
                    'reason'          => $escalationReason,
                ]);
                $this->escalationHandler->escalate($conversation, $escalationReason, $storeId);

                $pauseText = "Vielen Dank für Ihre Anfrage.\n\n"
                    . "Ihre Nachricht wurde zur Überprüfung an unser Team weitergeleitet. "
                    . "Wir melden uns in Kürze bei Ihnen.";
                $pauseHtml = '<p>Vielen Dank für Ihre Anfrage.</p>'
                    . '<p>Ihre Nachricht wurde zur Überprüfung an unser Team weitergeleitet. '
                    . 'Wir melden uns in Kürze bei Ihnen.</p>';

                $escalationMessageId = $this->generateOutboundMessageId();
                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                    'content_text' => $pauseText,
                    'content_html' => $pauseHtml,
                    'intent'       => 'escalated',
                    'message_id'   => $escalationMessageId,
                ]);
                try {
                    $channel = $this->channels[$message->getChannelType()] ?? $this->defaultChannel;
                    $channel->sendResponse($message, $pauseText, $pauseHtml, [
                        'message_id' => $escalationMessageId,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 7b] Escalation pause reply failed – ' . $e->getMessage());
                }
                $this->pipelineLogger->section('ESCALATED');
                $this->pipelineLogger->data('Reason', $escalationReason);
                return ['text' => $pauseText, 'html' => $pauseHtml];
            }

            // Steps 8–10 wrapped so that any unhandled exception still writes an outbound
            // record to the DB, keeping conversation history alternating for the next LLM call.
            $responseText       = $llmResult['response_text'] ?? '';
            $responseHtml       = $llmResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
            // Strip the degraded marker from display strings (HTML comment is invisible but ugly in plain text).
            // Keep the note visible to the customer; strip marker+note only for DB history storage.
            $historyText        = $this->contextBuilder->stripDegradedNote($responseText);
            $responseText       = str_replace(ContextBuilder::DEGRADED_MARKER, '', $responseText);
            $responseHtml       = str_replace(ContextBuilder::DEGRADED_MARKER, '', $responseHtml);
            $toolCalls          = $llmResult['tool_calls'] ?? [];
            $toolResults        = [];
            $outboundPersisted  = false;
            $toolProvidedResponse = false;
            // The LLM may author the final reply itself and mark where the order number
            // belongs with a placeholder (see cart_checkout prompt). Capture that text up
            // front so intermediate tool status messages (cart_add_item etc.) cannot clobber it.
            $llmText            = $responseText;
            $llmHtml            = $responseHtml;
            $orderPlaceholder   = OrderConfirmationFormatter::hasPlaceholder($llmText)
                || OrderConfirmationFormatter::hasPlaceholder($llmHtml);
            try {
                // Step 8: Execute tool_calls via MagentoToolExecutor
                if (!empty($toolCalls)) {
                    $this->logger->info('[STEP 8] Executing tool_calls', [
                        'session'    => $sid,
                        'tool_names' => array_column($toolCalls, 'name'),
                    ]);
                    foreach ($toolCalls as $toolCall) {
                        $toolName   = $toolCall['name'] ?? '';
                        $toolParams = $toolCall['params'] ?? [];
                        $this->logger->info('[STEP 8] Tool: ' . $toolName, ['params' => $toolParams]);
                        $toolResult = $this->toolExecutor->execute(
                            $toolName,
                            $toolParams,
                            $customerData,
                            $storeId
                        );
                        $toolResults[] = ['tool' => $toolName, 'result' => $toolResult];
                        $this->logger->info('[STEP 8] Tool result: ' . $toolName, [
                            'success' => $toolResult['success'] ?? false,
                            'error'   => $toolResult['error'] ?? null,
                        ]);
                        $orderRef = (string)($toolResult['increment_id'] ?? '');
                        if ($orderPlaceholder
                            && $toolName === 'cart_checkout'
                            && !empty($toolResult['success'])
                            && $orderRef !== ''
                        ) {
                            // Keep the LLM's localized confirmation; inject only the real order
                            // number into the original LLM text (not the possibly-clobbered $responseText).
                            $merged       = OrderConfirmationFormatter::inject(
                                $llmText,
                                $llmHtml,
                                $orderRef,
                                $toolResult['response_text'] ?? '',
                                $toolResult['response_html'] ?? ''
                            );
                            $responseText = $merged['text'];
                            $responseHtml = $merged['html'];
                            $historyText  = str_replace(
                                OrderConfirmationFormatter::ORDER_NUMBER_PLACEHOLDER,
                                $orderRef,
                                $historyText
                            );
                            // The LLM text already carries greeting + signature — do not re-wrap (Step 8b).
                            $toolProvidedResponse = false;
                        } elseif ($orderPlaceholder && ($toolResult['success'] ?? false) === true) {
                            // Successful intermediate tool (cart_add_item/-remove/-update) while the LLM
                            // authored the final reply — ignore its status text so it can't clobber the
                            // placeholder before cart_checkout runs.
                            $this->logger->info('[STEP 8] Keeping LLM response; ignoring intermediate tool text', [
                                'session' => $sid,
                                'tool'    => $toolName,
                            ]);
                        } elseif (!empty($toolResult['response_text'])) {
                            // If the tool returned explicit text, override LLM pre-written response
                            $responseText = $toolResult['response_text'];
                            $responseHtml = $toolResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
                            $historyText  = $responseText;
                            $toolProvidedResponse = true;
                        }
                        // Abort the remaining chain on the first failure so a follow-up tool
                        // (e.g. cart_checkout after a partially failed cart_add_item) cannot
                        // place a silent partial order or overwrite the failure message.
                        if (($toolResult['success'] ?? false) !== true) {
                            $this->logger->warning('[STEP 8] Aborting remaining tool_calls after failure', [
                                'session' => $sid,
                                'tool'    => $toolName,
                                'error'   => $toolResult['error'] ?? null,
                            ]);
                            break;
                        }
                    }
                }

                // Safety net: strip any leftover order-number placeholder (e.g. the LLM used the
                // token but no successful cart_checkout substituted it) so it never reaches the customer.
                if ($orderPlaceholder) {
                    $responseText = OrderConfirmationFormatter::stripPlaceholder($responseText);
                    $responseHtml = OrderConfirmationFormatter::stripPlaceholder($responseHtml);
                    $historyText  = OrderConfirmationFormatter::stripPlaceholder($historyText);
                }

                // Step 8b: Tool results replace the LLM's text wholesale and therefore drop
                // the salutation/farewell the model had written. Re-wrap them so tool-driven
                // mails (product search, order history, wishlist, …) keep the same greeting
                // and sign-off as every other mail. Limited to e-mail — chat channels don't
                // use letter-style greetings.
                if ($toolProvidedResponse && $message->getChannelType() === 'email') {
                    $responseText = $this->salutationProvider->wrapText($responseText, $customerData, $storeId);
                    $responseHtml = $this->salutationProvider->wrapHtml($responseHtml, $customerData, $storeId);
                    $historyText  = $responseText;
                }

                // Step 9: Persist outbound BEFORE delivering to the client so that the DB
                // record exists before the browser can trigger a follow-up request.
                // The outbound Message-Id is generated here and reused as the mail header
                // (Step 10) so a later reply to this mail threads back to this conversation.
                $outboundMessageId = $this->generateOutboundMessageId();
                $outboundPersisted = true;
                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                    'content_text'  => $historyText,
                    'content_html'  => $responseHtml,
                    'intent'        => $llmResult['intent'] ?? 'unknown',
                    'message_id'    => $outboundMessageId,
                    'intent_data'   => json_encode([
                        'tool_calls'      => $toolCalls,
                        'tool_results'    => $toolResults,
                        'extracted_items' => $llmResult['extracted_items'] ?? [],
                    ]),
                ]);

                // Step 10: Send response via the appropriate channel
                $channel = $this->channels[$message->getChannelType()] ?? $this->defaultChannel;
                $channel->sendResponse(
                    $message,
                    $responseText,
                    $responseHtml,
                    [
                        'inline_images' => $llmResult['inline_images'] ?? [],
                        'message_id'    => $outboundMessageId,
                    ]
                );
                $this->logger->info('[STEP 10] Response sent', [
                    'session'          => $sid,
                    'to'               => $message->getCustomerIdentifier(),
                    'inline_images'    => count($llmResult['inline_images'] ?? []),
                    'response_preview' => mb_substr($responseText, 0, 300),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[MessageProcessor] Processing failed after inbound save – ' . $e->getMessage(), [
                    'session' => $sid,
                ]);
                $this->errorNotifier->notify('Pipeline-Fehler', $e->getMessage(), $storeId);
                if (!$outboundPersisted) {
                    // Ensure the LLM sees an assistant turn in history on the next request
                    // so it understands this message was not processed successfully.
                    $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                        'content_text' => 'Ihre Nachricht konnte leider nicht verarbeitet werden. Bitte versuchen Sie es erneut.',
                        'intent'       => 'error',
                    ]);
                }
                throw $e;
            }

            // Update conversation status and force updated_at refresh so the admin
            // grid (sorted by updated_at DESC) always shows the conversation at the top.
            // Without setDataChanges(true), Magento skips the UPDATE when status is
            // unchanged and MySQL's ON UPDATE CURRENT_TIMESTAMP never fires.
            // Never overwrite STATUS_ESCALATED here — EscalationService already saved it.
            if ($conversation->getStatus() !== ConversationInterface::STATUS_ESCALATED) {
                $conversation->setStatus(
                    ($llmResult['intent'] ?? '') === 'ask_clarification'
                        ? ConversationInterface::STATUS_PENDING
                        : ConversationInterface::STATUS_OPEN
                )->setDataChanges(true);
                $this->conversationResource->save($conversation);
            }

            // Build webchat HTML: replace email CID references with inline Base64 data URIs
            // so the browser can display product images without needing MIME attachments.
            $webchatHtml = $responseHtml;
            foreach ($llmResult['inline_images'] ?? [] as $cid => $imgData) {
                $dataUri     = 'data:' . ($imgData['mime'] ?? 'image/jpeg') . ';base64,' . ($imgData['data'] ?? '');
                $webchatHtml = str_replace('cid:' . $cid, $dataUri, $webchatHtml);
            }

            $durationMs = (int)round((microtime(true) - $start) * 1000);
            $this->pipelineLogger->section('FINAL RESPONSE');
            $this->pipelineLogger->raw('Response text (plain)', $responseText);
            $this->pipelineLogger->raw('Response HTML', $responseHtml);
            $this->pipelineLogger->data('Intent', $llmResult['intent'] ?? '?');
            $this->pipelineLogger->data('Tool calls', array_column($toolCalls, 'name'));
            $this->pipelineLogger->finishRequest($durationMs);

            $this->logger->info('=== PIPELINE END ===', [
                'session'      => $sid,
                'duration_ms'  => $durationMs,
                'intent'       => $llmResult['intent'] ?? '?',
                'tool_calls'   => array_column($toolCalls, 'name'),
            ]);

            return ['text' => $responseText, 'html' => $webchatHtml];
        } finally {
            if ($storeId > 0) {
                $this->storeManager->setCurrentStore($originalStoreId);
            }
        }
    }

    private function sendUnauthorizedReply(UnifiedMessageInterface $message): void
    {
        $text = "Guten Tag,\n\n"
            . "vielen Dank für Ihre Nachricht.\n\n"
            . "Leider ist Ihre E-Mail-Adresse (" . $message->getCustomerIdentifier() . ") "
            . "in unserem System nicht als Kundenkonto hinterlegt. "
            . "Unser KI-Bestellassistent steht ausschließlich registrierten Kunden zur Verfügung.\n\n"
            . "Falls Sie Kunde werden möchten oder Ihre Adresse geändert hat, "
            . "wenden Sie sich bitte direkt an uns.\n\n"
            . "Mit freundlichen Grüßen\nIhr Shop-Team";

        $html = '<p>Guten Tag,</p>'
            . '<p>vielen Dank für Ihre Nachricht.</p>'
            . '<p>Leider ist Ihre E-Mail-Adresse (<strong>'
            . htmlspecialchars($message->getCustomerIdentifier())
            . '</strong>) in unserem System nicht als Kundenkonto hinterlegt. '
            . 'Unser KI-Bestellassistent steht ausschließlich registrierten Kunden zur Verfügung.</p>'
            . '<p>Falls Sie Kunde werden möchten oder Ihre Adresse geändert hat, '
            . 'wenden Sie sich bitte direkt an uns.</p>'
            . '<p>Mit freundlichen Grüßen<br>Ihr Shop-Team</p>';

        try {
            $channel = $this->channels[$message->getChannelType()] ?? $this->defaultChannel;
            $channel->sendResponse($message, $text, $html);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Zwernemann_Chat: Failed to send unauthorized reply – ' . $e->getMessage()
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function resolveCustomer(UnifiedMessageInterface $message): ?array
    {
        try {
            $customer = $this->customerLookup->findByEmail($message->getCustomerIdentifier());
            if ($customer) {
                $message->setMagentoCustomerId((int)$customer['id']);
                $message->setCustomerVerified(true);
            }
            return $customer;
        } catch (\Throwable $e) {
            $this->logger->warning('Zwernemann_Chat: Customer lookup failed – ' . $e->getMessage());
            return null;
        }
    }

    private function getOrCreateConversation(
        UnifiedMessageInterface $message,
        ?array $customerData
    ): Conversation {
        // Step A: thread resolution. A reply to an earlier mail carries the In-Reply-To /
        // References chain; if it points at a message we already stored, re-attach to that
        // conversation — reopening it when it had been closed — so the history is preserved.
        $threaded = $this->resolveConversationByThread($message);
        if ($threaded !== null) {
            return $threaded;
        }

        // Step B: fall back to the newest still-open conversation of the session.
        $conversation = $this->conversationFactory->create();
        $this->conversationResource->loadBySessionId($conversation, $message->getSessionId(), $message->getStoreId());

        if (!$conversation->getId()) {
            $conversation->setSessionId($message->getSessionId())
                ->setChannelType($message->getChannelType())
                ->setCustomerEmail($message->getCustomerIdentifier())
                ->setMagentoCustomerId($message->getMagentoCustomerId())
                ->setStoreId($message->getStoreId())
                ->setStatus(ConversationInterface::STATUS_OPEN);
            $this->conversationResource->save($conversation);
        }

        return $conversation;
    }

    /**
     * Re-attach an inbound reply to the conversation it continues, using the mail
     * threading chain (In-Reply-To / References). Returns null when there is no usable
     * reference, no stored match, the match belongs to a different customer, or the
     * matched conversation was closed longer ago than the configured reopen window —
     * in all those cases the caller falls back to the session lookup / a fresh row.
     *
     * A closed (resolved) match within the window is reopened: status → open and the
     * topic boundary is cleared so the LLM/RAG see the reopened topic's full history.
     */
    private function resolveConversationByThread(UnifiedMessageInterface $message): ?Conversation
    {
        $referenceIds = $message->getReferenceMessageIds();
        if (empty($referenceIds)) {
            return null;
        }

        $conversationId = $this->messageResource->findConversationIdByMessageIds($referenceIds);
        if ($conversationId === null) {
            return null;
        }

        $conversation = $this->conversationFactory->create();
        $this->conversationResource->load($conversation, $conversationId);
        if (!$conversation->getId()) {
            return null;
        }

        // Safety: never let a forged In-Reply-To hijack another customer's conversation.
        // Prefer the resolved Magento customer id (handles alias addresses); fall back to
        // the session id (= sender email) when no customer is linked yet.
        $sameCustomer = $message->getMagentoCustomerId() !== null
            && $conversation->getMagentoCustomerId() === $message->getMagentoCustomerId();
        $sameSession  = strcasecmp($conversation->getSessionId(), $message->getSessionId()) === 0;
        if (!$sameCustomer && !$sameSession) {
            $this->logger->warning('[STEP 2] Thread reference points to a different customer — ignored', [
                'session'         => $message->getSessionId(),
                'conversation_id' => $conversationId,
            ]);
            return null;
        }

        $status = $conversation->getStatus();

        // Escalated/open/pending threads are simply the active row — return as-is and let
        // the normal pipeline (incl. the escalation hold) handle them.
        if ($status !== ConversationInterface::STATUS_RESOLVED) {
            $this->logger->info('[STEP 2] Reply threaded onto existing conversation', [
                'session'         => $message->getSessionId(),
                'conversation_id' => $conversationId,
                'status'          => $status,
            ]);
            return $conversation;
        }

        // Resolved match: reopen only if it was closed recently enough.
        $windowDays = (int)$this->scopeConfig->getValue(
            'zwernemann_chat/imap/reopen_window_days',
            ScopeInterface::SCOPE_STORE,
            $conversation->getStoreId()
        );
        if ($windowDays > 0) {
            $updatedAt = strtotime((string)$conversation->getUpdatedAt());
            if ($updatedAt !== false
                && ($this->dateTime->gmtTimestamp() - $updatedAt) > $windowDays * 86400
            ) {
                $this->logger->info('[STEP 2] Threaded conversation closed beyond reopen window — starting fresh', [
                    'session'         => $message->getSessionId(),
                    'conversation_id' => $conversationId,
                    'window_days'     => $windowDays,
                ]);
                return null;
            }
        }

        $conversation->setStatus(ConversationInterface::STATUS_OPEN)
            ->setTopicStartedAt(null)
            ->setDataChanges(true);
        $this->conversationResource->save($conversation);

        $this->logger->info('[STEP 2] Reopened resolved conversation from reply thread', [
            'session'         => $message->getSessionId(),
            'conversation_id' => $conversationId,
        ]);

        return $conversation;
    }

    /**
     * A unique, bare RFC Message-ID (no angle brackets) for an outbound mail. Stored on
     * the outbound message row and used verbatim as the mail's Message-Id header, so the
     * customer's reply can be threaded back to this conversation.
     */
    private function generateOutboundMessageId(): string
    {
        $host = gethostname() ?: 'cc.local';
        return 'cc.' . bin2hex(random_bytes(16)) . '@' . $host;
    }

    /**
     * Starts a new topic when a topic change was detected.
     *
     * Email/WhatsApp get a fresh conversation row (the old one is resolved) so the admin
     * grid stays readable per person. Webchat keeps its single conversation — the widget
     * renders the running thread from browser storage — and only advances the topic
     * boundary so the LLM/RAG ignore the previous topic without clearing the window.
     *
     * @param array<int, array<string, mixed>> $extractedItems
     */
    private function startNewTopic(
        Conversation $conversation,
        UnifiedMessageInterface $message,
        string $resolvedQuery,
        array $extractedItems
    ): Conversation {
        $subject = $this->topicResolver->deriveSubject($resolvedQuery, $extractedItems);

        if (!in_array($message->getChannelType(), ['email', 'whatsapp'], true)) {
            $conversation->setTopicStartedAt($this->dateTime->gmtDate());
            if ($subject !== '' && $conversation->getSubject() === '') {
                $conversation->setSubject($subject);
            }
            $this->conversationResource->save($conversation);
            return $conversation;
        }

        $conversation->setStatus(ConversationInterface::STATUS_RESOLVED);
        $this->conversationResource->save($conversation);

        $new = $this->conversationFactory->create();
        $new->setSessionId($conversation->getSessionId())
            ->setChannelType($message->getChannelType())
            ->setCustomerEmail($conversation->getCustomerEmail())
            ->setMagentoCustomerId($conversation->getMagentoCustomerId())
            ->setStoreId($conversation->getStoreId())
            ->setStatus(ConversationInterface::STATUS_OPEN);
        if ($subject !== '') {
            $new->setSubject($subject);
        }
        $this->conversationResource->save($new);

        return $new;
    }

    /**
     * One placeholder line per attachment, e.g. "[Anhang: Angebot_2026-0457.pdf (PDF)]".
     * Persisted alongside the message body so attachment-only emails never produce
     * empty inbound records — history consumers and the query classifier depend on it.
     *
     * @param array<int, array<string, mixed>> $attachments
     */
    private function buildAttachmentPlaceholder(array $attachments): string
    {
        $lines = [];
        foreach ($attachments as $attachment) {
            $filename = trim((string)($attachment['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $ext  = strtoupper((string)pathinfo($filename, PATHINFO_EXTENSION));
            $type = $ext !== '' ? $ext : 'Datei';
            $lines[] = '[Anhang: ' . $filename . ' (' . $type . ')]';
        }
        return implode("\n", $lines);
    }

    /**
     * Detects whether the previous turn ended with an open clarification question.
     * Returns ['question' => string, 'items' => array] when the most recent outbound
     * message has intent=ask_clarification (transient 'error' rows are skipped so a
     * failed delivery doesn't mask the question), otherwise [].
     *
     * @param array<int, array<string, mixed>> $recentHistory  Messages in DESC order (newest first)
     * @return array{question?: string, items?: array<int, array<string, mixed>>}
     */
    private function findPendingClarification(array $recentHistory): array
    {
        foreach ($recentHistory as $row) {
            if (($row['direction'] ?? '') !== ConversationMessage::DIRECTION_OUTBOUND) {
                continue;
            }
            if (($row['intent'] ?? '') === 'error') {
                continue;
            }
            if (($row['intent'] ?? '') !== 'ask_clarification') {
                return [];
            }
            $intentData = json_decode((string)($row['intent_data'] ?? ''), true);
            $items      = is_array($intentData) ? ($intentData['extracted_items'] ?? []) : [];
            return [
                'question' => (string)($row['content_text'] ?? ''),
                'items'    => is_array($items) ? $items : [],
            ];
        }
        return [];
    }

    private function persistMessage(
        Conversation $conversation,
        UnifiedMessageInterface $message,
        string $direction,
        array $extra = []
    ): void {
        try {
            $msg = $this->messageFactory->create();
            $msg->setData([
                'conversation_id' => $conversation->getId(),
                'direction'       => $direction,
                'channel_type'    => $message->getChannelType(),
                'message_id'      => $extra['message_id'] ?? $message->getMessageId(),
                'content_text'    => $extra['content_text'] ?? $message->getContentText(),
                'content_html'    => $extra['content_html'] ?? null,
                'intent'          => $extra['intent'] ?? null,
                'intent_data'     => $extra['intent_data'] ?? null,
            ]);
            $this->messageResource->save($msg);
        } catch (\Throwable $e) {
            $this->logger->error('Zwernemann_Chat: Failed to persist message – ' . $e->getMessage());
        }
    }
}
