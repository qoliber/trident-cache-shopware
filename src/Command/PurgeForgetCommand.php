<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:purge:forget', description: 'Drop the pending purges of an instance that is no longer configured')]
class PurgeForgetCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries, private readonly SettingsProvider $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('instance', InputArgument::REQUIRED, 'Instance name, exactly as trident:purge:status shows it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('instance');
        if (\in_array($name, $this->settings->get()->instanceNames(), true)) {
            $output->writeln(sprintf('"%s" is a configured instance: its purges are still owed. Drain them instead.', $name));

            return self::FAILURE;
        }
        $output->writeln(sprintf('Dropped %d row(s) owed to "%s".', $this->deliveries->delivery()->forget($name), $name));

        return self::SUCCESS;
    }
}
