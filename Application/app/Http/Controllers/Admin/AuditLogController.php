<?php

namespace App\Http\Controllers\Admin;

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

        $logs = AuditLog::query()
            ->with(['actor', 'targetUser'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->string('action').'%'))
            ->when($request->filled('result'), fn ($q) => $q->where('result', $request->string('result')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit-logs.index', compact('logs'));
    }

    public function show(Request $request, AuditLog $auditLog, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'audit.log.view');

        $auditLog->load(['actor', 'targetUser']);

        return view('admin.audit-logs.show', ['log' => $auditLog]);
    }
}
