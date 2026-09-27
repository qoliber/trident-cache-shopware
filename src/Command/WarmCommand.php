<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Command;

use Qoliber\Trident\Admin\AdminException;
use Qoliber\Trident\Admin\AdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trident:warm', description: "Queue this shop's pages (home + canonical SEO URLs) on every Trident instance's warmer")]
class WarmCommand extends Command
{
    public function __construct(private readonly AdminService $admin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'URLs at most', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->admin->warmShop(max(1, (int) $input->getOption('limit')));
        } catch (AdminException $e) {
            $output->writeln($e->getMessage());

            return self::FAILURE;
        }
        $output->writeln(sprintf('Queued %d URL(s).', $result['queued']));
        $ok = true;
        foreach ($result['results'] as $row) {
            $ok = $ok && $row['ok'];
            $output->writeln(sprintf('  %-20s %s', $row['instance'], $row['ok'] ? 'queued' : 'FAILED — ' . $row['error']));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
