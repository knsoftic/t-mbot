<?php

namespace App\Livewire;

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Notifications')]
class Notifications extends Component
{
    use WithPagination;

    #[Url(except: false)]
    public bool $unreadOnly = false;

    public function updatedUnreadOnly(): void
    {
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function delete(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->delete();
    }

    public function clearRead(): void
    {
        auth()->user()->readNotifications()->delete();
    }

    public function render()
    {
        $query = $this->unreadOnly ? auth()->user()->unreadNotifications() : auth()->user()->notifications();

        return view('livewire.notifications', [
            'notifications' => $query->latest()->paginate(25),
            'unread' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
