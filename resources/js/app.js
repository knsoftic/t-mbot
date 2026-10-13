/**
 * Alpine stores shared by every admin page. Livewire ships Alpine, so we only
 * register stores here; they are available as $store.toasts / $store.confirm.
 */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('toasts', {
        items: [],
        add(toast) {
            const id = Date.now() + Math.random();
            this.items.push({ id, type: toast.type || 'success', message: toast.message || '' });
            setTimeout(() => this.remove(id), toast.timeout || (toast.type === 'error' ? 8000 : 4500));
        },
        remove(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    });

    Alpine.store('confirm', {
        open: false,
        title: '',
        message: '',
        confirmText: 'Confirm',
        danger: true,
        callback: null,
        ask(options, callback) {
            Object.assign(this, { title: 'Are you sure?', message: '', confirmText: 'Confirm', danger: true }, options || {});
            this.callback = callback;
            this.open = true;
        },
        accept() {
            const callback = this.callback;
            this.close();
            if (callback) callback();
        },
        close() {
            this.open = false;
            this.callback = null;
        },
    });

    Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try {
                localStorage.setItem('theme', this.dark ? 'dark' : 'light');
            } catch (e) {}
        },
    });
});

// Livewire components call $this->dispatch('toast', message: '...', type: 'success').
window.addEventListener('toast', (event) => {
    window.Alpine?.store('toasts').add(event.detail || {});
});
