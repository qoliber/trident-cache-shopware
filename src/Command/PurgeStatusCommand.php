<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:purge:status', description: 'Show the purge outbox and the configured Trident instances')]
class PurgeStatusCommand extends Command
{
    /** Seconds after which a pending purge is a problem, not a retry. */
    public const STALE_AFTER = 900;

    public function __construct(private readonly DeliveryFactory $deliveries, private readonly SettingsProvider $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settings->get();
        $status = $this->deliveries->delivery()->status();
        $output->writeln(sprintf('Instances (%s), purge mode %s%s:', $settings->source, $settings->mode, $settings->tagPrefix !== '' ? ', tag prefix ' . $settings->tagPrefix : ''));
        foreach ($settings->instances as $instance) {
            $output->writeln(sprintf('  %-20s %-40s pending %d', $instance->name, $instance->apiUrl, $status['by_instance'][$instance->name] ?? 0));
        }
        foreach ($settings->errors as $error) {
            $output->writeln('  skipped: ' . $error);
        }
        foreach ($status['orphaned'] as $name => $count) {
            $output->writeln(sprintf('  %-20s NOT CONFIGURED — %d row(s) kept; drop them with trident:purge:forget %s', $name, $count, $name));
        }
        $output->writeln(sprintf('Pending: %d%s', $status['pending'], $status['oldest_age'] !== null ? sprintf(' (oldest %d s)', $status['oldest_age']) : ''));
        $output->writeln(sprintf('Scheduled backstop purges: %d', $status['scheduled'] ?? 0));
        $output->writeln('Last failure: ' . ($status['last_error'] ?? '-'));
        $stale = $status['oldest_age'] !== null && $status['oldest_age'] > self::STALE_AFTER;
        if ($stale) {
            $output->writeln(sprintf('WARNING: a purge has been waiting longer than %ds — pages may be out of date. Check trident:check and trident:purge:drain.', self::STALE_AFTER));
        }

        return $settings->errors === [] && $status['orphaned'] === [] && !$stale ? self::SUCCESS : self::FAILURE;
    }
}
