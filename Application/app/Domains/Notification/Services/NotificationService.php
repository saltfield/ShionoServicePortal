<?php

namespace App\Domains\Notification\Services;

use App\Domains\Notification\Enums\NotificationType;
use App\Models\Application;
use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Models\User;
use App\Notifications\ContractApplicationNotification;
use App\Notifications\ForcePasswordChangeNotification;
use App\Notifications\TicketMessageNotification;

class NotificationService
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients,
    ) {}

    public function notifyTicketOpened(Inquiry $inquiry, InquiryMessage $message, User $actor): void
    {
        $inquiry->loadMissing(['openedBy', 'assigneeBp', 'issuerBp']);

        $this->dispatcher->send(
            NotificationType::TicketMessage,
            $this->recipients->ticketAssigneeSide($inquiry),
            new TicketMessageNotification($inquiry, $message, $actor, isOpened: true),
            except: $actor,
        );
    }

    public function notifyTicketReply(Inquiry $inquiry, InquiryMessage $message, User $actor, bool $actorIsAssigneeSide): void
    {
        $inquiry->loadMissing(['openedBy', 'assigneeBp', 'issuerBp']);

        $targets = $actorIsAssigneeSide
            ? $this->recipients->ticketIssuerSide($inquiry)
            : $this->recipients->ticketAssigneeSide($inquiry);

        $this->dispatcher->send(
            NotificationType::TicketMessage,
            $targets,
            new TicketMessageNotification($inquiry, $message, $actor, isOpened: false),
            except: $actor,
        );
    }

    /**
     * @param  'submitted'|'approved'|'rejected'|'forwarded'  $event
     */
    public function notifyApplication(Application $application, User $actor, string $event): void
    {
        $application->loadMissing(['contract', 'fromBp', 'toBp']);

        $targets = match ($event) {
            'submitted', 'forwarded' => $this->recipients->applicationApprovers($application),
            'approved', 'rejected' => $this->recipients->applicationRequester($application),
            default => collect(),
        };

        $this->dispatcher->send(
            NotificationType::ContractApplication,
            $targets,
            new ContractApplicationNotification($application, $actor, $event),
            except: $actor,
        );
    }

    public function notifyForcePasswordChange(User $target, User $actor): void
    {
        $this->dispatcher->send(
            NotificationType::SecurityPasswordForce,
            [$target],
            new ForcePasswordChangeNotification($actor),
        );
    }
}
