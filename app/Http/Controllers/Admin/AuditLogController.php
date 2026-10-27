<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'user' => 'nullable|integer',
            'action' => 'nullable|string|max:100',
            'q' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $logs = AuditLog::with('user')
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $action === 'login.*'
                ? $q->where('action', 'like', 'login%')
                : $q->where('action', $action))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$text}%")
                ->orWhere('email', 'like', "%{$text}%")))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', \Carbon\Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', \Carbon\Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::byLastName(User::all()),
            'actions' => AuditLog::ACTIONS,
        ]);
    }
}
