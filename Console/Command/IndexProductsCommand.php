<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zwernemann\Chat\Model\Rag\ProductIndexer;

class IndexProductsCommand extends Command
{
    public function __construct(
        private readonly ProductIndexer      $indexer,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cc:index:products')
            ->setDescription('Index Magento products into Pinecone via Voyage AI embeddings')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-index all products, ignoring checksum')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store code or ID to index (default: all stores)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force       = (bool)$input->getOption('force');
        $storeOption = $input->getOption('store');
        $storeId     = 0;

        if ($storeOption !== null) {
            $storeId = $this->resolveStoreId((string)$storeOption, $output);
            if ($storeId === null) {
                return Command::FAILURE;
            }
        }

        $scope = $storeId > 0
            ? sprintf('store ID %d', $storeId)
            : 'all stores';
        $output->writeln(sprintf('<info>Zwernemann_Chat: Starting product indexing (%s)...</info>', $scope));

        $bar = null;
        $this->indexer->indexAll($force, function (int $done, int $total) use ($output, &$bar) {
            if ($bar === null) {
                $bar = new ProgressBar($output, $total);
                $bar->start();
            }
            $bar->setProgress($done);
        }, $storeId);

        if ($bar) {
            $bar->finish();
            $output->writeln('');
        }

        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }

    private function resolveStoreId(string $storeOption, OutputInterface $output): ?int
    {
        // Try numeric ID first
        if (ctype_digit($storeOption)) {
            return (int)$storeOption;
        }

        // Try store code
        foreach ($this->storeManager->getStores(true) as $store) {
            if ($store->getCode() === $storeOption) {
                return (int)$store->getId();
            }
        }

        $output->writeln(sprintf('<error>Store "%s" not found.</error>', $storeOption));
        return null;
    }
}
