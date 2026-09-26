<?php

namespace App\Domains\Notification\Enums;

enum NotificationType: string
{
    case TicketMessage = 'ticket.message';
    case ContractApplication = 'contract.application';
    case SecurityPasswordForce = 'security.password_force';

    public function label(): string
    {
        return match ($this) {
            self::TicketMessage => 'チケット（起票・返信）',
            self::ContractApplication => '価格申請（申請・決裁結果）',
            self::SecurityPasswordForce => '強制パスワード変更',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TicketMessage => 'チケットの起票や返信があったときにメールで通知します。',
            self::ContractApplication => '価格承認・価格変更の申請や決裁結果をメールで通知します。',
            self::SecurityPasswordForce => '管理者がパスワード強制変更を設定したときに通知します（オフにできません）。',
        };
    }

    public function allowsOptOut(): bool
    {
        return $this !== self::SecurityPasswordForce;
    }

    /**
     * @return list<self>
     */
    public static function userConfigurable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type) => $type->allowsOptOut()
        ));
    }
}
