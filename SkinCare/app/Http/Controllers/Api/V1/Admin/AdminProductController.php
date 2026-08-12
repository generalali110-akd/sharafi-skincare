<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminProductIndexRequest;
use App\Http\Resources\Api\V1\Admin\AdminProductListResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminProductController extends Controller
{
    public function index(AdminProductIndexRequest $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->with([
                'brand:id,name,slug',
                'variants.inventory:id,variant_id,on_hand,reserved',
            ])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $search = trim((string) $request->validated('q'));
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', '%'.$search.'%'));
            });
        }

        if ($request->filled('status')) {
            $status = $request->enum('status', ProductStatus::class);
            $query->where('status', $status?->value);
        }

        return AdminProductListResource::collection(
            $query->paginate((int) $request->validated('per_page', 25))->withQueryString(),
        );
    }
}
