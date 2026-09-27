<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:purge:drain', description: 'Deliver the purges waiting in the outbox to every Trident instance')]
class PurgeDrainCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows to deliver at most', '1000')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore the backoff: retry failed rows now')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Deliver every row now, whatever its due time (second deliveries, backstop rows) — after an incident, or in tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = $this->deliveries->delivery();
        $limit = max(1, (int) $input->getOption('limit'));
        $report = $input->getOption('now') ? $delivery->drainAll($limit) : $delivery->drain($limit, (bool) $input->getOption('force'));
        $output->writeln(sprintf('Delivered %d purge(s); %d failed; %d entries purged.', $report->delivered, $report->failed, $report->purged));
        foreach ($report->instances as $name => $row) {
            $output->writeln(sprintf('  %-20s delivered %d, failed %d%s', $name, $row['delivered'], $row['failed'], $row['error'] ? ' — ' . $row['error'] : ''));
        }
        $status = $delivery->status();
        $output->writeln(sprintf('Pending: %d', $status['pending']));

        return $report->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
