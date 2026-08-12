<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Complete schema for the free Chat module.
 *
 * Every table is created only when absent, so this runs cleanly both on a fresh
 * install and on a database that already carries the tables from a previous
 * single-module install. Escalation columns on cc_conversation are added by the
 * premium ConversationalCommerce module, not here.
 */
class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $this->createConversationsTable($setup);
        $this->createConversationMessagesTable($setup);
        $this->createProductIndexLogTable($setup);
        $this->createCustomerAliasEmailTable($setup);
        $this->createLlmUsageLogTable($setup);
        $this->createRejectedMessageTable($setup);

        $setup->endSetup();
    }

    private function createConversationsTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_conversation');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('session_id', Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false, 'default' => 'webchat'])
        ->addColumn('customer_email', Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('magento_customer_id', Table::TYPE_INTEGER, null, ['nullable' => true, 'unsigned' => true])
        ->addColumn('status', Table::TYPE_TEXT, 20, ['nullable' => false, 'default' => 'open'])
        ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
            'nullable' => false, 'unsigned' => true, 'default' => 0,
        ], 'Magento store_id — 0 = default/fallback')
        ->addColumn('subject', Table::TYPE_TEXT, 255, [
            'nullable' => true,
        ], 'Derived topic/subject shown in the conversation grid')
        ->addColumn('topic_started_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => true,
        ], 'Boundary of the current topic; LLM/RAG context only uses messages created at or after this')
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addColumn('updated_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE,
        ])
        ->addIndex($setup->getIdxName('cc_conversation', ['session_id']), ['session_id'])
        ->addIndex($setup->getIdxName('cc_conversation', ['customer_email']), ['customer_email'])
        ->addIndex($setup->getIdxName('cc_conversation', ['status']), ['status'])
        ->addIndex($setup->getIdxName('cc_conversation', ['store_id']), ['store_id'])
        ->setComment('Chat – Conversations');

        $setup->getConnection()->createTable($table);
    }

    private function createConversationMessagesTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_conversation_message');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('conversation_id', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true])
        ->addColumn('direction', Table::TYPE_TEXT, 10, ['nullable' => false])  // 'inbound' | 'outbound'
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false])
        ->addColumn('message_id', Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('content_text', Table::TYPE_TEXT, '64k', ['nullable' => false])
        ->addColumn('content_html', Table::TYPE_TEXT, '64k', ['nullable' => true])
        ->addColumn('intent', Table::TYPE_TEXT, 50, ['nullable' => true])
        ->addColumn('intent_data', Table::TYPE_TEXT, '64k', ['nullable' => true])  // JSON
        ->addColumn('order_id', Table::TYPE_INTEGER, null, ['nullable' => true, 'unsigned' => true])
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex(
            $setup->getIdxName('cc_conversation_message', ['conversation_id']),
            ['conversation_id']
        )
        ->addIndex(
            $setup->getIdxName('cc_conversation_message', ['message_id']),
            ['message_id']
        )
        ->addForeignKey(
            $setup->getFkName('cc_conversation_message', 'conversation_id', 'cc_conversation', 'id'),
            'conversation_id',
            $setup->getTable('cc_conversation'),
            'id',
            Table::ACTION_CASCADE
        )
        ->setComment('Chat – Messages per Conversation');

        $setup->getConnection()->createTable($table);
    }

    private function createProductIndexLogTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_product_index_log');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('product_id', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true])
        ->addColumn('sku', Table::TYPE_TEXT, 64, ['nullable' => false])
        ->addColumn('pinecone_id', Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
            'nullable' => false, 'unsigned' => true, 'default' => 0,
        ], 'Magento store_id for Pinecone namespace isolation')
        ->addColumn('indexed_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addColumn('checksum', Table::TYPE_TEXT, 64, ['nullable' => true])
        ->addIndex(
            $setup->getIdxName('cc_product_index_log', ['product_id', 'store_id'], AdapterInterface::INDEX_TYPE_UNIQUE),
            ['product_id', 'store_id'],
            ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
        )
        ->setComment('Chat – Product Pinecone Index Log');

        $setup->getConnection()->createTable($table);
    }

    private function createCustomerAliasEmailTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_customer_alias_email');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('customer_id', Table::TYPE_INTEGER, null, [
            'nullable' => false, 'unsigned' => true,
        ], 'Magento customer entity_id')
        ->addColumn('email', Table::TYPE_TEXT, 255, [
            'nullable' => false,
        ], 'Alias email address (lower-cased on insert)')
        ->addColumn('label', Table::TYPE_TEXT, 255, [
            'nullable' => true,
        ], 'Optional description (e.g. "Work laptop", "Colleague")')
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex(
            $setup->getIdxName('cc_customer_alias_email', ['email'], AdapterInterface::INDEX_TYPE_UNIQUE),
            ['email'],
            ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
        )
        ->addIndex(
            $setup->getIdxName('cc_customer_alias_email', ['customer_id']),
            ['customer_id']
        )
        ->setComment('Chat – Additional customer email addresses for sender authentication');

        $setup->getConnection()->createTable($table);
    }

    private function createLlmUsageLogTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_llm_usage_log');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('conversation_id', Table::TYPE_INTEGER, null, [
            'nullable' => true, 'unsigned' => true,
        ])
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false, 'default' => ''])
        ->addColumn('provider', Table::TYPE_TEXT, 20, ['nullable' => false, 'default' => ''])
        ->addColumn('model', Table::TYPE_TEXT, 100, ['nullable' => false, 'default' => ''])
        ->addColumn('input_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('output_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cache_write_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cache_read_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cost_usd', Table::TYPE_DECIMAL, '10,6', ['nullable' => false, 'default' => '0.000000'])
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['conversation_id']), ['conversation_id'])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['channel_type']), ['channel_type'])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['created_at']), ['created_at'])
        ->setComment('Chat – LLM API usage and cost log');

        $setup->getConnection()->createTable($table);
    }

    private function createRejectedMessageTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('cc_rejected_message');
        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('message_id', Table::TYPE_TEXT, 255, [
            'nullable' => false,
        ], 'RFC Message-Id of the rejected inbound mail')
        ->addColumn('from_address', Table::TYPE_TEXT, 255, [
            'nullable' => false, 'default' => '',
        ], 'Sender address that was rejected')
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex(
            $setup->getIdxName('cc_rejected_message', ['message_id'], AdapterInterface::INDEX_TYPE_UNIQUE),
            ['message_id'],
            ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
        )
        ->setComment('Chat – Rejected inbound messages (one rejection notice per mail)');

        $setup->getConnection()->createTable($table);
    }
}
