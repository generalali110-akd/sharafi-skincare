<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

final class CategoryHierarchyGuard
{
    public function assertCanMove(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if ($currentId === $category->getKey()) {
                throw ValidationException::withMessages([
                    'parent_id' => ['انتخاب این والد باعث ایجاد چرخه در دسته‌بندی‌ها می‌شود.'],
                ]);
            }

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['ساختار دسته‌بندی موجود دارای چرخه نامعتبر است.'],
                ]);
            }

            $visited[$currentId] = true;
            $parent = Category::query()->lockForUpdate()->find($currentId);

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['دسته‌بندی والد پیدا نشد.'],
                ]);
            }

            $currentId = $parent->parent_id;
        }
    }
}
