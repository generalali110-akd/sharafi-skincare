<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DatabaseLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = User::query()
            ->whereDoesntHave('roles')
            ->withCount('orders')
            ->withMax('orders', 'created_at')
            ->latest('id');

        if ($search = trim((string) ($validated['q'] ?? ''))) {
            $like = DatabaseLike::caseInsensitiveOperator();
            $query->where(function (Builder $query) use ($search, $like): void {
                $query->where('mobile', $like, '%'.$search.'%')
                    ->orWhere('name', $like, '%'.$search.'%');
            });
        }

        if ($status = trim((string) ($validated['status'] ?? ''))) {
            $query->where('status', $status);
        }

        $customers = $query
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'status' => $user->status,
                'orders_count' => (int) $user->orders_count,
                'last_order_at' => $user->orders_max_created_at,
                'created_at' => $user->created_at?->toISOString(),
            ]);

        return response()->json($customers);
    }
}
