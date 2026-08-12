<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AuditLogIndexRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()
            ->with('actor:id,name')
            ->latest('id');

        if ($request->filled('action')) {
            $query->where('action', (string) $request->validated('action'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_user_id', (int) $request->validated('actor_id'));
        }

        return AuditLogResource::collection(
            $query->paginate((int) $request->validated('per_page', 50))->withQueryString(),
        );
    }
}
