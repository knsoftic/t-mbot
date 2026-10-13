<div x-data x-show="$store.confirm.open" x-cloak class="fixed inset-0 z-[70] overflow-y-auto" role="alertdialog" aria-modal="true"
     x-on:keydown.escape.window="$store.confirm.close()">
    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-show="$store.confirm.open" x-transition.opacity x-on:click="$store.confirm.close()"></div>
    <div class="relative flex min-h-full items-end justify-center p-4 sm:items-center">
        <div class="card relative w-full max-w-md p-6" x-show="$store.confirm.open" x-transition x-trap.noscroll="$store.confirm.open">
            <div class="flex gap-4">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full"
                     :class="$store.confirm.danger ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400' : 'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300'">
                    <x-icon name="warning" class="size-5" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white" x-text="$store.confirm.title"></h3>
                    <p class="mt-1.5 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300" x-text="$store.confirm.message"></p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" x-on:click="$store.confirm.close()">Cancel</button>
                <button type="button" class="btn" :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'"
                        x-on:click="$store.confirm.accept()" x-text="$store.confirm.confirmText"></button>
            </div>
        </div>
    </div>
</div>
