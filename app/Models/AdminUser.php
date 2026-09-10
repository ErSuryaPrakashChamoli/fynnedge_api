<?php

namespace App\Models;

use App\Domain\Admin\AdminRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A member of FynnEdge staff.
 *
 * Deliberately not a `User`. It has a password and a browser session where a
 * customer has a mobile number and a one-time code, it lives in its own
 * table behind its own guard, and it has no relationship to any customer
 * record — staff reach those through the panel, not through Eloquent.
 *
 * There is no HasApiTokens here and there should not be: a member of staff
 * has no business holding a customer API token, and the absence is what
 * makes that true rather than a rule somebody has to remember.
 */
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * `last_login_at` is absent on purpose: it records what happened rather
     * than anything anyone may set, and the panel writes it directly.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role' => AdminRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Whether Filament lets this person in.
     *
     * Checked on every request the panel serves, not only at login, so
     * disabling an account ends the session already in progress rather than
     * waiting for it to expire. That is the difference between revoking
     * access and asking politely.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active === true;
    }

    public function role(): AdminRole
    {
        return $this->role instanceof AdminRole
            ? $this->role
            : AdminRole::from((string) $this->role);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === AdminRole::SuperAdmin;
    }

    /** The name Filament shows in the panel's account menu. */
    public function getFilamentName(): string
    {
        return $this->name;
    }
}
