<?php

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Read by the consumer (`messenger:consume async scheduler_default`). A letter
 * scheduled for the publication of a relevé is just a letter scheduled for
 * that time: one tick covers both.
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->add(RecurringMessage::every('1 minute', new SendDueNewsletters()))
            ->add(RecurringMessage::every('1 day', new PurgePendingSubscribers()));
    }
}
