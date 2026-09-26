<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ForcePasswordChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $actor,
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
        $type = $notifiable->user_type ?? null;
        $value = is_object($type) && property_exists($type, 'value') ? $type->value : (string) $type;
        $guard = match ($value) {
            'admin' => 'admin',
            'customer' => 'customer',
            default => 'bp',
        };
        $url = url('/'.$guard.'/password/change');

        return (new MailMessage)
            ->subject(sprintf('[%s] パスワードの変更が必要です', config('app.name')))
            ->greeting(($notifiable->name ?: $notifiable->login_id).' 様')
            ->line('管理者により、次回ログイン時のパスワード変更が要求されました。')
            ->line('セキュリティのため、できるだけ早くパスワードを変更してください。')
            ->action('パスワードを変更する', $url)
            ->line('この通知はオフにできません。')
            ->line('このメールは '.config('app.name').' から自動送信されています。');
    }
}
