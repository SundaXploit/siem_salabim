<?php

namespace App\Providers;

use App\Http\Middleware\EnforceSessionSecurity;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\User;
use App\Policies\AlertPolicy;
use App\Services\CompatibleEncrypter;
use App\Services\EncryptedSearchIndex;
use App\Services\OpenSearchService;
use App\Services\TelegramService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('encrypter', fn ($encrypter, $app) => (new CompatibleEncrypter($encrypter->getKey(), $app['config']['app.cipher']))
            ->previousKeys($encrypter->getPreviousKeys()));
        // Bind services as singletons
        $this->app->singleton(OpenSearchService::class);
        $this->app->singleton(TelegramService::class);
        $this->app->singleton(EncryptedSearchIndex::class);
    }

    public function boot(): void
    {
        Gate::policy(Alert::class, AlertPolicy::class);
        foreach (array_keys(User::PRIVILEGES) as $privilege) {
            Gate::define($privilege, fn (User $user) => $user->hasPrivilege($privilege));
        }
        // Use Bootstrap 5 paginator (closest to our custom CSS)
        Paginator::useBootstrapFive();
        Event::listen(Login::class, function (Login $event): void {
            if (request()->hasSession()) {
                EnforceSessionSecurity::start(request(), $event->user);
            }
            ActivityLog::record('login', $event->user);
        });
        Event::listen(Failed::class, fn (Failed $event) => ActivityLog::record('login', $event->user, 'Login ditolak', 'failed'));
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user) {
                ActivityLog::record('logout', $event->user);
            }
        });
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => ActivityLog::record('password_reset', $event->user));

        // Share new-alert count to app layout via a 30-second cache.
        // Prevents inline Model::count() from running on every page load.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $count = $user ? Cache::remember('siem_new_alert_count:'.$user->alertAccessFingerprint(), 30, fn () => Alert::visibleTo($user)->where('status', 'new')->count()
            ) : 0;
            $view->with('layoutNewAlertCount', $count);
        });
    }
}
