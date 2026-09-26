@php
    use App\Domains\Notification\Enums\NotificationType;

    /** @var array<string, bool> $preferences */
    $preferences = $preferences ?? [];
    $oldPreferences = old('preferences');
    $notificationTypes = NotificationType::cases();
@endphp

<div class="border-top pt-3 mt-3 mb-3">
    <input type="hidden" name="notification_preferences_present" value="1">
    <h2 class="h6 mb-2">メール通知設定</h2>
    <p class="small text-muted mb-3">未設定の種別は既定でオンです。強制パスワード変更通知はオフにできません。</p>

    @foreach ($notificationTypes as $type)
        @php
            if (is_array($oldPreferences)) {
                $checked = $type->allowsOptOut()
                    ? filter_var($oldPreferences[$type->value] ?? false, FILTER_VALIDATE_BOOLEAN)
                    : true;
            } else {
                $checked = (bool) ($preferences[$type->value] ?? true);
            }
            $fieldId = 'user_pref_'.str_replace('.', '_', $type->value);
        @endphp
        <div class="mb-2 form-check">
            <input
                type="checkbox"
                class="form-check-input"
                id="{{ $fieldId }}"
                name="preferences[{{ $type->value }}]"
                value="1"
                @checked($checked)
                @disabled(! $type->allowsOptOut())
            >
            <label class="form-check-label" for="{{ $fieldId }}">
                {{ $type->label() }}
                @unless ($type->allowsOptOut())
                    <span class="badge text-bg-secondary ms-1">必須</span>
                @endunless
            </label>
            <div class="form-text">{{ $type->description() }}</div>
        </div>
    @endforeach
</div>
