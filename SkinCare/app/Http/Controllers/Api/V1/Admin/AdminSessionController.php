<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->loadMissing('roles.permissions');

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $knownPermissions = Permissions::all();
        $permissions = $user->roles->contains('slug', 'super-admin')
            ? $knownPermissions
            : $user->roles
                ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
                ->filter(fn (string $permission) => in_array($permission, $knownPermissions, true))
                ->unique()
                ->sort()
                ->values()
                ->all();

        if ($permissions === []) {
            return response()->json([
                'message' => 'این حساب به پنل مدیریت دسترسی ندارد.',
            ], Response::HTTP_FORBIDDEN);
        }

        sort($permissions);

        return response()->json([
            'data' => [
                'name' => $user->name,
                'mobile' => $user->mobile,
                'permissions' => $permissions,
            ],
        ]);
    }
}
