<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates (or promotes) a Super Admin. The password is typed interactively
 * and never stored in the repository.
 */
#[Signature('app:create-admin {--name= : Full name} {--email= : E-mail address} {--password= : Password (omit to be prompted securely)}')]
#[Description('Create the first Super Admin user (or promote an existing user)')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $email = $this->option('email') ?: text('E-mail address', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid e-mail address.');
        $existing = User::query()->where('email', $email)->first();
        $name = $this->option('name') ?: ($existing?->name ?? text('Full name', required: true));
        $password = $this->option('password') ?: password('Password (min. 10 characters, mixed case, numbers)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            ['name' => ['required', 'max:120'], 'email' => ['required', 'email'], 'password' => ['required', Password::min(10)->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::query()->where('slug', Role::SUPER_ADMIN)->firstOrFail();

        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => $password,
            'role_id' => $role->id,
            'status' => UserStatus::Active,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'password_changed_at' => now()])->save();

        $this->info(($existing ? 'Updated' : 'Created')." Super Admin {$user->email}.");

        return self::SUCCESS;
    }
}
