<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\IranMobile;
use Illuminate\Console\Command;

class GrantRoleCommand extends Command
{
    protected $signature = 'access:grant-role
        {mobile : Verified Iranian mobile number of the existing user}
        {role : Existing role slug}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Grant an existing role to an active, mobile-verified user';

    public function handle(AuditLogger $auditLogger): int
    {
        $mobile = IranMobile::normalize((string) $this->argument('mobile'));
        $roleSlug = trim((string) $this->argument('role'));

        if (! IranMobile::isValid($mobile)) {
            $this->error('Invalid Iranian mobile number.');

            return self::INVALID;
        }

        $user = User::query()->where('mobile', $mobile)->first();

        if (! $user) {
            $this->error('User not found. The user must authenticate normally before a role can be granted.');

            return self::FAILURE;
        }

        if (! $user->mobile_verified_at) {
            $this->error('User mobile is not verified.');

            return self::FAILURE;
        }

        if ($user->status !== 'active') {
            $this->error('Inactive users cannot receive administrative roles.');

            return self::FAILURE;
        }

        $role = Role::query()->where('slug', $roleSlug)->first();

        if (! $role) {
            $this->error('Role not found. Seed system access definitions before granting roles.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Grant role [{$role->slug}] to [{$mobile}]?")) {
            $this->warn('No changes made.');

            return self::SUCCESS;
        }

        $result = $user->roles()->syncWithoutDetaching([$role->id]);

        if ($result['attached'] !== []) {
            $auditLogger->record(
                actor: null,
                action: 'access.role.granted',
                subject: $user,
                changes: [
                    'role' => $role->slug,
                    'source' => 'console',
                ],
                metadata: [
                    'command' => 'access:grant-role',
                ],
            );
        }

        $this->info("Role [{$role->slug}] granted to [{$mobile}].");

        return self::SUCCESS;
    }
}
