<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\ContentMediaController;
use App\Livewire\AuditLogs;
use App\Livewire\Bots;
use App\Livewire\Campaigns;
use App\Livewire\Contents;
use App\Livewire\Dashboard;
use App\Livewire\Destinations;
use App\Livewire\FailedJobs;
use App\Livewire\Notifications;
use App\Livewire\PostingLogs;
use App\Livewire\Profile;
use App\Livewire\Roles;
use App\Livewire\Schedules;
use App\Livewire\Settings;
use App\Livewire\SystemHealth;
use App\Livewire\Tags;
use App\Livewire\Users;
use App\Support\Permissions as P;
use Illuminate\Support\Facades\Route;

Route::get('/branding/logo', BrandingLogoController::class)->name('branding.logo');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/profile', Profile::class)->name('profile');
    Route::livewire('/notifications', Notifications::class)->name('notifications');

    // Telegram
    Route::livewire('/bots', Bots\Index::class)->middleware('can:'.P::BOTS_VIEW)->name('bots.index');
    Route::livewire('/destinations', Destinations\Index::class)->middleware('can:'.P::DESTINATIONS_VIEW)->name('destinations.index');
    Route::livewire('/channels', Destinations\Index::class)->middleware('can:'.P::DESTINATIONS_VIEW)->name('destinations.channels');
    Route::livewire('/groups', Destinations\Index::class)->middleware('can:'.P::DESTINATIONS_VIEW)->name('destinations.groups');

    // Content
    Route::livewire('/contents', Contents\Index::class)->middleware('can:'.P::CONTENTS_VIEW)->name('contents.index');
    Route::livewire('/contents/create', Contents\Form::class)->middleware('can:'.P::CONTENTS_MANAGE)->name('contents.create');
    Route::livewire('/contents/{content}/edit', Contents\Form::class)->middleware('can:'.P::CONTENTS_MANAGE)->name('contents.edit');
    Route::get('/contents/{content}/media', ContentMediaController::class)->middleware('can:'.P::CONTENTS_VIEW)->name('contents.media');
    Route::livewire('/tags', Tags\Index::class)->middleware('can:'.P::CONTENTS_VIEW)->name('tags.index');

    // Automation
    Route::livewire('/campaigns', Campaigns\Index::class)->middleware('can:'.P::CAMPAIGNS_VIEW)->name('campaigns.index');
    Route::livewire('/campaigns/create', Campaigns\Wizard::class)->middleware('can:'.P::CAMPAIGNS_MANAGE)->name('campaigns.create');
    Route::livewire('/campaigns/{campaign}/edit', Campaigns\Wizard::class)->middleware('can:'.P::CAMPAIGNS_MANAGE)->name('campaigns.edit');
    Route::livewire('/campaigns/{campaign}', Campaigns\Show::class)->middleware('can:'.P::CAMPAIGNS_VIEW)->name('campaigns.show');
    Route::livewire('/schedules', Schedules\Index::class)->middleware('can:'.P::CAMPAIGNS_VIEW)->name('schedules.index');

    // Monitoring
    Route::livewire('/logs', PostingLogs\Index::class)->middleware('can:'.P::LOGS_VIEW)->name('logs.index');
    Route::livewire('/failed-jobs', FailedJobs\Index::class)->middleware('can:'.P::FAILED_JOBS_MANAGE)->name('failed-jobs.index');
    Route::livewire('/audit-logs', AuditLogs\Index::class)->middleware('can:'.P::AUDIT_VIEW)->name('audit-logs.index');

    // Users
    Route::livewire('/users', Users\Index::class)->middleware('can:'.P::USERS_MANAGE)->name('users.index');
    Route::livewire('/roles', Roles\Index::class)->middleware('can:'.P::ROLES_MANAGE)->name('roles.index');

    // System
    Route::livewire('/settings', Settings\Index::class)->middleware('can:'.P::SETTINGS_MANAGE)->name('settings.index');
    Route::livewire('/system-health', SystemHealth\Index::class)->middleware('can:'.P::SYSTEM_HEALTH)->name('system-health');
});
