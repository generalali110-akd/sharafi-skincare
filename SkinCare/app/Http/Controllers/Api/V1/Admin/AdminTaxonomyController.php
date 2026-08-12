<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBrandRequest;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBrandRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CategoryHierarchyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminTaxonomyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CategoryHierarchyGuard $hierarchyGuard,
    ) {}

    public function storeBrand(StoreBrandRequest $request): JsonResponse
    {
        $brand = DB::transaction(function () use ($request): Brand {
            $brand = Brand::query()->create($request->validated());
            $this->auditLogger->record(
                actor: $request->user(),
                action: 'catalog.brand.created',
                subject: $brand,
                changes: $brand->only(['name', 'slug', 'is_active']),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $brand;
        });

        return response()->json(['data' => $this->brandPayload($brand)], Response::HTTP_CREATED);
    }

    public function updateBrand(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $brand = DB::transaction(function () use ($request, $brand): Brand {
            $brand = Brand::query()->lockForUpdate()->findOrFail($brand->getKey());
            $before = $brand->only(['name', 'slug', 'is_active']);
            $brand->fill($request->validated());
            $brand->save();
            $after = $brand->only(['name', 'slug', 'is_active']);

            if ($before !== $after) {
                $this->auditLogger->record(
                    actor: $request->user(),
                    action: 'catalog.brand.updated',
                    subject: $brand,
                    changes: ['before' => $before, 'after' => $after],
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }

            return $brand;
        });

        return response()->json(['data' => $this->brandPayload($brand)]);
    }

    public function storeCategory(StoreCategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request): Category {
            $category = Category::query()->create($request->validated());
            $this->auditLogger->record(
                actor: $request->user(),
                action: 'catalog.category.created',
                subject: $category,
                changes: $category->only(['parent_id', 'name', 'slug', 'is_active', 'sort_order']),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $category;
        });

        return response()->json(['data' => $this->categoryPayload($category)], Response::HTTP_CREATED);
    }

    public function updateCategory(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = DB::transaction(function () use ($request, $category): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->getKey());

            if ($request->has('parent_id')) {
                $this->hierarchyGuard->assertCanMove(
                    $category,
                    $request->input('parent_id') === null ? null : (int) $request->validated('parent_id'),
                );
            }

            $before = $category->only(['parent_id', 'name', 'slug', 'is_active', 'sort_order']);
            $category->fill($request->validated());
            $category->save();
            $after = $category->only(['parent_id', 'name', 'slug', 'is_active', 'sort_order']);

            if ($before !== $after) {
                $this->auditLogger->record(
                    actor: $request->user(),
                    action: 'catalog.category.updated',
                    subject: $category,
                    changes: ['before' => $before, 'after' => $after],
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }

            return $category;
        });

        return response()->json(['data' => $this->categoryPayload($category)]);
    }

    private function brandPayload(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'is_active' => $brand->is_active,
        ];
    }

    private function categoryPayload(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
        ];
    }
}
