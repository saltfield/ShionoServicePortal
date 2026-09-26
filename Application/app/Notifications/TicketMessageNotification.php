<?php

namespace App\Notifications;

use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly InquiryMessage $message,
        public readonly User $actor,
        public readonly bool $isOpened,
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
        $url = url('/'.$guard.'/tickets/'.$this->inquiry->id);
        $subject = $this->isOpened
            ? sprintf('[%s] 新しいチケット: %s', config('app.name'), $this->inquiry->subject)
            : sprintf('[%s] チケット返信: %s', config('app.name'), $this->inquiry->subject);

        $bodyPreview = mb_strimwidth((string) $this->message->body, 0, 200, '…');

        return (new MailMessage)
            ->subject($subject)
            ->greeting(($notifiable->name ?: $notifiable->login_id).' 様')
            ->line($this->isOpened
                ? '新しいチケットが起票されました。'
                : 'チケットに返信がありました。')
            ->line('件名: '.$this->inquiry->subject)
            ->line('投稿者: '.($this->actor->name ?: $this->actor->login_id))
            ->line($bodyPreview !== '' ? '内容: '.$bodyPreview : '')
            ->action('チケットを開く', $url)
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
