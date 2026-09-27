<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\Trident\Http\TransportFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:check', description: 'Check the connection to every configured Trident instance')]
class CheckCommand extends Command
{
    public function __construct(private readonly SettingsProvider $settings, private readonly TransportFactory $transports)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settings->get();
        if (!$settings->enabled()) {
            $output->writeln('No Trident instance configured (TRIDENT_INSTANCES, TRIDENT_API_URL or the plugin settings).');

            return self::FAILURE;
        }
        $ok = true;
        $transport = $this->transports->transport();
        foreach ($settings->instances as $instance) {
            $status = (new PurgeClient($instance, $transport))->status();
            $ok = $ok && $status['ok'];
            $output->writeln(sprintf('  %-20s %-40s %s %s', $instance->name, $instance->apiUrl, $status['ok'] ? 'OK  ' : 'FAIL', $status['message']));
        }
        foreach ($settings->errors as $error) {
            $ok = false;
            $output->writeln('  skipped: ' . $error);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
