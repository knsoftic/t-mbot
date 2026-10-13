<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Toasts and consistent handling of service-level validation errors.
 */
trait InteractsWithUi
{
    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    /**
     * Toast shown on the next page (use before a redirect).
     */
    protected function flash(string $message, string $type = 'success'): void
    {
        session()->flash('toast', ['message' => $message, 'type' => $type]);
    }

    /**
     * Run a service call; turn ValidationExceptions into field errors + an error toast.
     */
    protected function attempt(callable $callback, ?string $success = null): bool
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            $this->toast(collect($e->errors())->flatten()->first() ?: 'The action could not be completed.', 'error');

            return false;
        }

        if ($success !== null) {
            $this->toast($success);
        }

        return true;
    }
}
