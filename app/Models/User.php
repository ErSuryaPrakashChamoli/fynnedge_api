<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A FynnEdge customer, identified by mobile number.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['mobile', 'name', 'email', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(FinancialProfile::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function aiMemories(): HasMany
    {
        return $this->hasMany(AiMemory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LoanApplication::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(FinancialGoal::class);
    }

    /** Everything FynnEdge has told this customer. */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** Handsets this customer has registered for push. */
    public function notificationDevices(): HasMany
    {
        return $this->hasMany(NotificationDevice::class);
    }

    /** What this customer last had to be told about a value that moves. */
    public function notificationWatermarks(): HasMany
    {
        return $this->hasMany(NotificationWatermark::class);
    }

    /** Financial decisions this customer explored. */
    public function decisionContexts(): HasMany
    {
        return $this->hasMany(DecisionContext::class);
    }

    /** What has happened in this customer's financial life. */
    public function financialMoments(): HasMany
    {
        return $this->hasMany(FinancialMoment::class);
    }

    /** What was true about their money, at the moments FynnEdge looked. */
    public function financialObservations(): HasMany
    {
        return $this->hasMany(FinancialObservation::class);
    }

    /** Requests this customer has made for help. */
    public function supportCases(): HasMany
    {
        return $this->hasMany(SupportCase::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /**
     * Both profiles are created empty on first sign-in, so every downstream
     * service can rely on them existing rather than null-checking everywhere.
     */
    public function ensureProfiles(): void
    {
        /*
         * createOrFirst rather than firstOrCreate.
         *
         * Both rows carry a unique index on user_id, and firstOrCreate looks
         * before it inserts — so two sign-ins arriving together for the same
         * customer both find nothing and both insert, and the loser raises
         * on the index instead of getting the row that already exists.
         * createOrFirst inserts inside a savepoint and reads the winner back.
         */
        $this->customerProfile()->createOrFirst([]);
        $this->financialProfile()->createOrFirst([]);
        $this->refresh();
    }

    /**
     * The customer's credit reports, newest first.
     *
     * Deleted when bureau consent is withdrawn — see CreditBureauService.
     */
    public function creditReports(): HasMany
    {
        return $this->hasMany(CreditReport::class)->orderByDesc('obtained_at');
    }

    /** Every attempt to read one of this customer's documents. */
    public function documentScans(): HasMany
    {
        return $this->hasMany(DocumentScan::class)->orderByDesc('id');
    }

    /** Every attempt to verify one of this customer's documents. */
    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class)->orderByDesc('id');
    }
}
