<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:purge', description: 'Purge Shopware cache tags (or --all) from every Trident instance, through the durable outbox')]
class PurgeCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tags', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Shopware cache tags, e.g. product-<id>')
            ->addOption('all', null, InputOption::VALUE_NONE, "Purge the whole shop (its 'all' tag)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = $this->deliveries->delivery();
        $rows = $input->getOption('all') ? $delivery->recordAll() : $delivery->record((array) $input->getArgument('tags'));
        if ($rows === 0) {
            $output->writeln('Nothing to purge (no tags, or no Trident instance configured).');

            return self::FAILURE;
        }
        $report = $delivery->flush();
        $output->writeln(sprintf('Recorded %d row(s); delivered %d, failed %d (kept for retry).', $rows, $report->delivered, $report->failed));

        return $report->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
