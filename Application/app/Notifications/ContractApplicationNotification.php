<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractApplicationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'submitted'|'approved'|'rejected'|'forwarded'  $event
     */
    public function __construct(
        public readonly Application $application,
        public readonly User $actor,
        public readonly string $event,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $guard = $this->guardFor($notifiable);
        $url = url('/'.$guard.'/contracts/'.$this->application->contract_id.'?tab=items');
        $typeLabel = $this->application->type->label();

        $eventLine = match ($this->event) {
            'submitted' => '新しい価格申請が提出されました。承認をお願いします。',
            'forwarded' => '価格申請が上位承認へ転送されました。承認をお願いします。',
            'approved' => '価格申請が承認されました。',
            'rejected' => '価格申請が却下されました。',
            default => '価格申請の状態が更新されました。',
        };

        $subject = sprintf('[%s] %s #%d', config('app.name'), $typeLabel, $this->application->id);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(($notifiable->name ?: $notifiable->login_id).' 様')
            ->line($eventLine)
            ->line('申請種別: '.$typeLabel)
            ->line('申請ID: #'.$this->application->id)
            ->line('契約ID: #'.$this->application->contract_id)
            ->action('申請を開く', $url)
            ->line('このメールは '.config('app.name').' から自動送信されています。');
    }

    private function guardFor(object $notifiable): string
    {
        $type = $notifiable->user_type ?? null;
        $value = is_object($type) && property_exists($type, 'value') ? $type->value : (string) $type;

        return match ($value) {
            'admin' => 'admin',
            'customer' => 'customer',
            default => 'bp',
        };
    }
}
