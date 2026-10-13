<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records who did what. Failures to write the audit log never break the
 * action being audited.
 */
class AuditLogger
{
    /**
     * Keys that must never end up in audit metadata.
     */
    private const SECRET_KEYS = ['token', 'password', 'password_confirmation', 'current_password', 'secret', 'api_key'];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?Model $entity = null, ?string $description = null, array $metadata = [], ?User $user = null): ?AuditLog
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            return AuditLog::create([
                'user_id' => ($user ?? Auth::user())?->getKey(),
                'action' => $action,
                'entity_type' => $entity ? $entity::class : null,
                'entity_id' => $entity?->getKey(),
                'description' => $description ? Str::limit($description, 250) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
                'metadata' => $this->scrub($metadata) ?: null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not write audit log', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Attribute changes of a model, without secrets, for "edited" entries.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changes(Model $model): array
    {
        $changes = [];

        foreach ($model->getChanges() as $key => $value) {
            if (in_array($key, ['updated_at', 'token_hash'], true)) {
                continue;
            }

            $changes[$key] = in_array($key, self::SECRET_KEYS, true)
                ? ['from' => '[hidden]', 'to' => '[changed]']
                : ['from' => $model->getOriginal($key), 'to' => $value];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::SECRET_KEYS, true)) {
                $data[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DATE_ATOM);
            }
        }

        return $data;
    }
}
