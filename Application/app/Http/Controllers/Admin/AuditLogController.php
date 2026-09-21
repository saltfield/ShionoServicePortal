<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'audit.log.view');

        $filters = [
            'category' => trim((string) $request->input('category', '')),
            'action' => trim((string) $request->input('action', '')),
            'result' => trim((string) $request->input('result', '')),
            'actor_type' => trim((string) $request->input('actor_type', '')),
            'org' => trim((string) $request->input('org', '')),
        ];

        $logs = AuditLog::query()
            ->with(['actor.businessPartner', 'actor.customer', 'targetUser'])
            ->when($filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', 'like', '%'.$filters['action'].'%'))
            ->when($filters['result'] !== '', fn ($q) => $q->where('result', $filters['result']))
            ->when(
                in_array($filters['actor_type'], ['admin', 'bp', 'customer'], true),
                function ($q) use ($filters) {
                    $q->whereHas('actor', fn ($actor) => $actor->where('user_type', $filters['actor_type']));
                }
            )
            ->when($filters['org'] !== '', function ($q) use ($filters) {
                $like = '%'.$filters['org'].'%';
                $q->whereHas('actor', function ($actor) use ($like) {
                    $actor->where(function ($scope) use ($like) {
                        $scope->where(function ($bp) use ($like) {
                            $bp->where('user_type', UserType::Bp->value)
                                ->whereHas('businessPartner', function ($partner) use ($like) {
                                    $partner->where('code', 'like', $like)
                                        ->orWhere('name', 'like', $like);
                                });
                        })->orWhere(function ($customer) use ($like) {
                            $customer->where('user_type', UserType::Customer->value)
                                ->whereHas('customer', function ($org) use ($like) {
                                    $org->where('code', 'like', $like)
                                        ->orWhere('name', 'like', $like);
                                });
                        });
                    });
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, AuditLog $auditLog, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'audit.log.view');

        $auditLog->load(['actor.businessPartner', 'actor.customer', 'targetUser']);

        return view('admin.audit-logs.show', ['log' => $auditLog]);
    }
}
