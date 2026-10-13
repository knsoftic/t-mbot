<div x-data class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
    @if (session('toast'))
        <div x-init="$store.toasts.add(@js(session('toast')))"></div>
    @endif
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition.opacity.duration.200ms
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl bg-white p-4 shadow-lg ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
            <div :class="{
                    'text-emerald-500': toast.type === 'success',
                    'text-red-500': toast.type === 'error',
                    'text-amber-500': toast.type === 'warning',
                    'text-brand-500': toast.type === 'info',
                 }">
                <template x-if="toast.type === 'success'"><x-icon name="check-circle" class="size-5" /></template>
                <template x-if="toast.type === 'error'"><x-icon name="x-circle" class="size-5" /></template>
                <template x-if="toast.type === 'warning'"><x-icon name="warning" class="size-5" /></template>
                <template x-if="toast.type === 'info'"><x-icon name="info" class="size-5" /></template>
            </div>
            <p class="flex-1 text-sm text-slate-700 dark:text-slate-200" x-text="toast.message"></p>
            <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" x-on:click="$store.toasts.remove(toast.id)" aria-label="Dismiss">
                <x-icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>
