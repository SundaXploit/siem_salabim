<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        return $user->canAccessAlert($alert);
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $user->canAccessAlert($alert) && $user->hasPrivilege('alerts.acknowledge');
    }

    public function ignore(User $user, Alert $alert): bool
    {
        return $user->canAccessAlert($alert) && $user->hasPrivilege('alerts.ignore');
    }

    public function notify(User $user, Alert $alert): bool
    {
        return $user->canAccessAlert($alert) && $user->hasPrivilege('alerts.notify');
    }

    public function analyze(User $user, Alert $alert): bool
    {
        return $user->canAccessAlert($alert) && $user->hasPrivilege('alerts.analyze');
    }
}
