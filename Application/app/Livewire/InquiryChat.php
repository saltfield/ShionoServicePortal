<?php

namespace App\Livewire;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryMessageType;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\InquiryService;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithFileUploads;

class InquiryChat extends Component
{
    use WithFileUploads;

    public int $inquiryId;

    public string $routePrefix = 'admin';

    public bool $readOnly = false;

    public string $body = '';

    /** @var array<int, mixed> */
    public array $attachments = [];

    public function mount(int $inquiryId, string $routePrefix = 'admin', bool $readOnly = false): void
    {
        $this->inquiryId = $inquiryId;
        $this->routePrefix = $routePrefix;
        $this->readOnly = $readOnly;

        $actor = $this->actor();
        if ($this->readOnly) {
            $this->service()->assertAdminInspectable($actor, $this->inquiry());
        } else {
            $this->service()->assertVisible($actor, $this->inquiry());
            $this->service()->markRead($actor, $this->inquiry());
        }
    }

    public function send(InquiryService $service, RbacService $rbac): void
    {
        abort_if($this->readOnly, 403);

        $actor = $this->actor();
        abort_unless($rbac->hasPermission($actor, 'inquiry.reply'), 403);

        $this->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.InquiryService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimes:png,jpg,jpeg,gif,heic,heif,pdf',
            ],
        ]);

        $files = array_values(array_filter(
            $this->attachments,
            fn ($upload) => $upload !== null
        ));

        if (trim((string) $this->body) === '' && $files === []) {
            $this->addError('body', '本文または添付が必要です。');

            return;
        }

        $beforeStatus = $this->inquiry()->status;

        try {
            $service->reply($actor, $this->inquiry(), (string) $this->body, $files);
            $this->body = '';
            $this->attachments = [];
            $this->service()->markRead($actor, $this->inquiry());

            if ($beforeStatus !== $this->inquiry()->status) {
                $this->redirect(route($this->routePrefix.'.tickets.show', $this->inquiryId), navigate: false);
            }
        } catch (InvalidArgumentException $exception) {
            $this->addError('body', $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    public function render(): View
    {
        $inquiry = $this->inquiry()->load(['messages.user', 'messages.attachments', 'assigneeBp']);
        $status = $inquiry->status;
        $canReply = ! $this->readOnly
            && ! in_array($status, [InquiryStatus::Closed, InquiryStatus::Withdrawn], true);

        $assigneeUserIds = [];
        if ($this->readOnly) {
            $service = $this->service();
            foreach ($inquiry->messages as $message) {
                if ($message->user === null) {
                    continue;
                }
                $assigneeUserIds[$message->user_id] = $service->isAssigneeSide($message->user, $inquiry);
            }
        }

        return view('livewire.inquiry-chat', [
            'inquiry' => $inquiry,
            'canReply' => $canReply,
            'isClosed' => $status === InquiryStatus::Closed,
            'isWithdrawn' => $status === InquiryStatus::Withdrawn,
            'statusChangeType' => InquiryMessageType::StatusChange->value,
            'readOnly' => $this->readOnly,
            'assigneeUserIds' => $assigneeUserIds,
        ]);
    }

    private function actor(): User
    {
        $user = auth($this->routePrefix)->user();
        if (! $user instanceof User) {
            foreach (['admin', 'bp', 'customer'] as $guard) {
                $candidate = auth($guard)->user();
                if ($candidate instanceof User) {
                    $this->routePrefix = $guard;

                    return $candidate;
                }
            }
            abort(403);
        }

        return $user;
    }

    private function inquiry(): Inquiry
    {
        return Inquiry::query()->findOrFail($this->inquiryId);
    }

    private function service(): InquiryService
    {
        return app(InquiryService::class);
    }
}
