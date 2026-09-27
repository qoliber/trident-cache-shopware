<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\ScheduledTask;

use Psr\Log\LoggerInterface;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: DrainPurgeOutboxTask::class)]
final class DrainPurgeOutboxTaskHandler extends ScheduledTaskHandler
{
    public const LIMIT = 1000;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $log,
        private readonly DeliveryFactory $deliveries,
    ) {
        parent::__construct($scheduledTaskRepository, $log);
    }

    public function run(): void
    {
        $report = $this->deliveries->delivery()->drain(self::LIMIT);
        if ($report->failed > 0) {
            // Not an exception: the rows are kept with their backoff, and the
            // task itself must keep running on schedule.
            $this->log->warning('Trident: purges kept for retry', ['failed' => $report->failed, 'instances' => $report->instances]);
        }
    }
}
