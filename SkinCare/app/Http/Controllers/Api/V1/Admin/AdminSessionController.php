<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AdminSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = $user->roles()->with('permissions:id,slug')->get(['roles.id', 'roles.slug']);
        $roleSlugs = $roles->pluck('slug')->values();

        $permissions = $roleSlugs->contains('super-admin')
            ? collect(Permissions::all())
            : $roles->flatMap(fn ($role) => $role->permissions->pluck('slug'));

        $permissions = $permissions->unique()->sort()->values();

        if ($permissions->isEmpty()) {
            throw new AccessDeniedHttpException('دسترسی به پنل مدیریت برای این حساب فعال نیست.');
        }

        return response()->json([
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'mobile' => $user->mobile,
                ],
                'roles' => $roleSlugs,
                'permissions' => $permissions,
            ],
        ]);
    }
}
