<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An operator of the system.
 *
 * Roles are granted per company: config/permission.php runs Spatie in teams
 * mode with team_foreign_key = company_id, so the same user can be an
 * Accountant in one company and an Auditor in another.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'job_title', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /**
     * The guard roles and permissions are stored under.
     *
     * Both the `web` and the `sanctum` guard resolve their user from this same
     * model, and Spatie derives the guard to look roles up under from
     * config('auth.defaults.guard') at call time. Authenticating an API request
     * through `auth:sanctum` calls Auth::shouldUse('sanctum'), which rewrites
     * that default — so without this property an API request would look for
     * roles under the `sanctum` guard while the seeder wrote them under `web`,
     * and every permission check would quietly answer false.
     *
     * Declaring it takes precedence over that lookup: Guard::getNames() returns
     * this single name and never consults the auth default.
     *
     * @var string
     */
    protected $guard_name = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * The two-factor columns are cast as encrypted, so the secret and the
     * recovery codes only ever exist in plaintext inside this process. They are
     * deliberately absent from #[Fillable] above: they are written through
     * forceFill by App\Domain\Access\TwoFactor and by nothing else, so no
     * request payload can ever reach them by mass assignment.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'row_version' => 'integer',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['is_default', 'status'])
            ->withTimestamps();
    }

    /**
     * Membership of the tenant, which is what every company-scoped policy and
     * every team-scoped role check starts from.
     */
    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    /**
     * The company a request works in when the caller names no other one.
     */
    public function defaultCompanyId(): ?int
    {
        $default = $this->companies()
            ->wherePivot('is_default', true)
            ->orderBy('companies.id')
            ->first();

        return $default?->getKey() ?? $this->companies()->orderBy('companies.id')->value('companies.id');
    }

    public function createdJournals(): HasMany
    {
        return $this->hasMany(Journal::class, 'created_by_user_id');
    }

    public function postedJournals(): HasMany
    {
        return $this->hasMany(Journal::class, 'posted_by_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
