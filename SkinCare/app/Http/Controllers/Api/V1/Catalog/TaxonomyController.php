<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class TaxonomyController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => Category::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'slug']),
        ]);
    }

    public function brands(): JsonResponse
    {
        return response()->json([
            'data' => Brand::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }
}
