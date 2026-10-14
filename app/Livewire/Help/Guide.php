<?php

namespace App\Livewire\Help;

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Step-by-step user guide for the admin panel.
 */
#[Title('User Guide')]
class Guide extends Component
{
    public const SECTIONS = [
        'quick-start' => 'Quick start',
        'account' => 'Login aur account',
        'dashboard' => 'Dashboard',
        'bots' => 'Bots',
        'destinations' => 'Channels aur Groups',
        'content' => 'Content Library',
        'ai' => 'AI se post likhna (offline)',
        'campaign-wizard' => 'Campaign banana',
        'triggers' => 'Trigger types',
        'campaign-run' => 'Campaign chalana',
        'failures' => 'Retry, failure aur repeat',
        'test-mode' => 'Test mode',
        'schedules' => 'Schedules',
        'monitoring' => 'Logs aur monitoring',
        'notifications' => 'Notifications',
        'users' => 'Users aur Roles',
        'billing' => 'Accounts, plans aur billing',
        'settings' => 'Settings',
        'health' => 'System Health',
        'faq' => 'Masail aur hal',
    ];

    public function render()
    {
        return view('livewire.help.guide', ['sections' => self::SECTIONS]);
    }
}
