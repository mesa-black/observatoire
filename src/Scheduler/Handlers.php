<?php

namespace App\Scheduler;

use App\Entity\Subscriber;
use App\Newsletter\Sender;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class Handlers
{
    public function __construct(
        private Sender $sender,
        private SubscriberRepository $subscribers,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function sendDueNewsletters(SendDueNewsletters $message): void
    {
        $this->sender->sendDue();
    }

    #[AsMessageHandler]
    public function purgePendingSubscribers(PurgePendingSubscribers $message): void
    {
        $this->subscribers->deletePendingBefore($this->clock->now()->modify('-'.Subscriber::PENDING_KEPT_DAYS.' days'));
    }
}
