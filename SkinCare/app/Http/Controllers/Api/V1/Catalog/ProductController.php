<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\ProductIndexRequest;
use App\Http\Resources\Api\V1\Catalog\ProductDetailResource;
use App\Http\Resources\Api\V1\Catalog\ProductListResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->published()
            ->with([
                'brand:id,name,slug',
                'categories' => fn ($query) => $query->active()->select('categories.id', 'name', 'slug'),
            ])
            ->withMin(['variants as min_price_irr' => fn ($query) => $query->active()], 'price_irr')
            ->withMax(['variants as max_price_irr' => fn ($query) => $query->active()], 'price_irr')
            ->withExists([
                'variants as in_stock' => fn ($query) => $query
                    ->active()
                    ->whereHas('inventory', fn ($inventory) => $inventory->whereColumn('on_hand', '>', 'reserved')),
            ]);

        if ($request->filled('q')) {
            $search = trim((string) $request->validated('q'));
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'ilike', '%'.$search.'%'));
            });
        }

        if ($request->filled('category')) {
            $slug = (string) $request->validated('category');
            $query->whereHas('categories', fn (Builder $category) => $category
                ->active()
                ->where('slug', $slug));
        }

        if ($request->filled('brand')) {
            $slug = (string) $request->validated('brand');
            $query->whereHas('brand', fn (Builder $brand) => $brand
                ->active()
                ->where('slug', $slug));
        }

        if ($request->filled('min_price')) {
            $minimum = (int) $request->validated('min_price');
            $query->whereHas('variants', fn (Builder $variant) => $variant
                ->active()
                ->where('price_irr', '>=', $minimum));
        }

        if ($request->filled('max_price')) {
            $maximum = (int) $request->validated('max_price');
            $query->whereHas('variants', fn (Builder $variant) => $variant
                ->active()
                ->where('price_irr', '<=', $maximum));
        }

        match ($request->validated('sort', 'default')) {
            'newest' => $query->latest('published_at'),
            'price-asc' => $query->orderBy('min_price_irr')->orderBy('id'),
            'price-desc' => $query->orderByDesc('min_price_irr')->orderBy('id'),
            default => $query->orderByDesc('is_featured')->latest('published_at')->orderBy('id'),
        };

        return ProductListResource::collection(
            $query->paginate((int) $request->validated('per_page', 12))->withQueryString(),
        );
    }

    public function show(string $slug): ProductDetailResource
    {
        $product = Product::query()
            ->published()
            ->with([
                'brand:id,name,slug',
                'categories' => fn ($query) => $query->active()->select('categories.id', 'name', 'slug'),
                'variants' => fn ($query) => $query
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with('inventory:id,variant_id,on_hand,reserved'),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        return new ProductDetailResource($product);
    }
}
