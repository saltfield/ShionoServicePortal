<?php

namespace App\Livewire;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\InquiryService;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

class InquiryChat extends Component
{
    public int $inquiryId;

    public string $routePrefix = 'admin';

    public string $body = '';

    public function mount(int $inquiryId, string $routePrefix = 'admin'): void
    {
        $this->inquiryId = $inquiryId;
        $this->routePrefix = $routePrefix;
        $this->service()->assertVisible($this->actor(), $this->inquiry());
    }

    public function send(InquiryService $service, RbacService $rbac): void
    {
        $actor = $this->actor();
        abort_unless($rbac->hasPermission($actor, 'inquiry.reply'), 403);

        try {
            $service->reply($actor, $this->inquiry(), $this->body);
            $this->body = '';
            $this->dispatch('inquiry-updated');
        } catch (InvalidArgumentException $exception) {
            $this->addError('body', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $inquiry = $this->inquiry()->load(['messages.user', 'customer', 'owningBp']);

        return view('livewire.inquiry-chat', [
            'inquiry' => $inquiry,
            'canReply' => $inquiry->status !== InquiryStatus::Closed
                || app(RbacService::class)->hasPermission($this->actor(), 'inquiry.reopen'),
            'isClosed' => $inquiry->status === InquiryStatus::Closed,
        ]);
    }

    private function actor(): User
    {
        $user = auth($this->routePrefix)->user();
        abort_unless($user instanceof User, 403);

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
