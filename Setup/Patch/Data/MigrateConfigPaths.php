<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Setup\Patch\Data;

use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Migrates existing store configuration from the previous single-module section
 * (the misspelled "conversional_commerce") to the free Chat module's section
 * "zwernemann_chat".
 *
 * Only the groups that belong to the free module are copied; premium groups
 * (imap, smtp, whatsapp, escalation, notifications) stay in the old section and
 * remain owned by the ConversationalCommerce module. Encrypted values (API keys,
 * passwords) are copied verbatim — the encryption key is per-install, not
 * per-path, so the ciphertext stays valid.
 *
 * Existing target values are never overwritten, so the patch is safe to re-run
 * and never clobbers configuration an operator already set under the new section.
 */
class MigrateConfigPaths implements DataPatchInterface
{
    private const OLD_SECTION = 'conversional_commerce';
    private const NEW_SECTION = 'zwernemann_chat';

    /**
     * Config groups that move into the free Chat module. Mistral and Gemini stay
     * in the premium section (conversional_commerce) and are therefore not listed.
     */
    private const GROUPS = [
        'general', 'llm', 'anthropic', 'voyage',
        'product_index', 'pinecone', 'tools', 'payment', 'magento_api', 'webchat',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ConfigResource $configResource,
        private readonly TypeListInterface $cacheTypeList
    ) {}

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table      = $this->moduleDataSetup->getTable('core_config_data');
        $migrated   = false;

        foreach (self::GROUPS as $group) {
            $oldPrefix = self::OLD_SECTION . '/' . $group . '/';
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['scope', 'scope_id', 'path', 'value'])
                    ->where('path LIKE ?', $oldPrefix . '%')
            );

            foreach ($rows as $row) {
                $newPath = self::NEW_SECTION . '/' . substr((string)$row['path'], strlen(self::OLD_SECTION) + 1);

                // Never overwrite a value already present under the new section.
                $exists = $connection->fetchOne(
                    $connection->select()
                        ->from($table, 'config_id')
                        ->where('scope = ?', $row['scope'])
                        ->where('scope_id = ?', (int)$row['scope_id'])
                        ->where('path = ?', $newPath)
                );
                if ($exists !== false) {
                    continue;
                }

                $this->configResource->saveConfig(
                    $newPath,
                    $row['value'],
                    (string)$row['scope'],
                    (int)$row['scope_id']
                );
                $migrated = true;
            }
        }

        if ($migrated) {
            $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
