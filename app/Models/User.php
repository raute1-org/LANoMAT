<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\AvatarStore;
use App\Modules\Identity\Support\DisplayNameResolver;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $discord_id
 * @property Role $role
 * @property string|null $avatar_url Remembered Discord source URL; never rendered, only fetched server-side.
 * @property string|null $avatar_path Local avatar copy on the private disk.
 * @property string|null $avatar_hash Content hash of the local copy, part of its URL.
 * @property Carbon|null $avatar_checked_at
 * @property Carbon|null $last_login_at
 * @property string|null $bio
 * @property string|null $steam_url
 * @property string|null $stream_url
 * @property string|null $profile_color
 * @property bool $share_location Whether other participants may see where this user is (voice room, game server).
 * @property array<string, bool>|null $notification_prefs
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar_src Relative URL of the local avatar (browser route), null = show a monogram.
 * @property-read bool $has_password
 * @property-read bool $is_staff
 * @property-read bool $can_access_admin
 */
#[Fillable(['name', 'email', 'password', 'discord_id', 'avatar_url', 'bio', 'steam_url', 'stream_url', 'profile_color', 'share_location', 'notification_prefs'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_url', 'avatar_path', 'avatar_hash'])]
#[Appends(['has_password', 'is_staff', 'can_access_admin', 'avatar_src'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Assign a random profile_color on creation (app code, not a DB trigger —
     * a lesson from v1). Deterministically testable via the hex regex.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank($user->profile_color)) {
                $user->profile_color = sprintf('#%06X', random_int(0, 0xFFFFFF));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'notification_prefs' => 'array',
            'share_location' => 'boolean',
            'avatar_checked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The avatar every UI renders: the relative URL of the local copy (served to logged-in users
     * only), or null so the UI shows a monogram. It never falls back to the Discord CDN URL.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarSrc(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => app(AvatarStore::class)->webUrl($this),
        );
    }

    /** The same avatar as a bearer-API path, for the native client DTOs. */
    public function nativeAvatarSrc(): ?string
    {
        return app(AvatarStore::class)->nativeUrl($this);
    }

    /** Filament panel avatar: the panel is session-authenticated, so the browser route works there. */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_src;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isOrga(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Orga], true);
    }

    /**
     * Helper-or-above: true for helper, orga, and admin. A helper is NOT an
     * orga (no `/admin` access — see canAccessPanel()), but may operate the
     * targeted `can` surfaces gated on this check (e.g. QR check-in).
     */
    public function isHelper(): bool
    {
        return in_array($this->role, [Role::Admin, Role::Orga, Role::Helper], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isOrga();
    }

    /**
     * Whether the user has a local password set. Discord-provisioned users
     * have none, so password-dependent flows (security settings, password
     * confirmation) must not be surfaced to them as unreachable dead ends.
     *
     * Appended (see #[Appends]) as `has_password` so the frontend can hide
     * password-dependent navigation without the (hidden) password hash
     * itself ever being serialized.
     *
     * @return Attribute<bool, never>
     */
    protected function hasPassword(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->password !== null,
        );
    }

    /**
     * Whether the user is staff (helper-or-above). Appended so the frontend can
     * conditionally render staff-only navigation (e.g. the Check-in link) without
     * duplicating the role hierarchy — authorization itself stays in policies.
     *
     * @return Attribute<bool, never>
     */
    protected function isStaff(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->isHelper(),
        );
    }

    /**
     * Whether the user may open the Filament admin panel (`/admin`). Mirrors the
     * rule in canAccessPanel() (orga or admin, NOT helper). Appended so the
     * frontend can show the admin link only where the panel is reachable,
     * without duplicating the role check — authorization stays in canAccessPanel().
     *
     * @return Attribute<bool, never>
     */
    protected function canAccessAdmin(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->isOrga(),
        );
    }

    /** @return HasMany<LinkedAccount, $this> */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(LinkedAccount::class);
    }

    public function linkedAccount(LinkedAccountProvider $provider): ?LinkedAccount
    {
        return $this->linkedAccounts->firstWhere('provider', $provider);
    }

    /**
     * The name to display for this user in the given provider context
     * (e.g. that provider's linked nickname), falling back to the LANoMAT
     * `name` when there is no context or no usable linked nickname.
     */
    public function displayNameFor(?LinkedAccountProvider $context = null): string
    {
        return (new DisplayNameResolver)->resolve($this, $context);
    }

    /**
     * The users this user has an accepted friendship with, in either
     * direction. Eloquent's HasMany can't OR two foreign keys onto one
     * relation, so this is a plain query helper rather than a relation.
     *
     * @return Collection<int, User>
     */
    public function acceptedFriends(): Collection
    {
        $friendships = Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(function ($query): void {
                $query->where('requester_id', $this->id)->orWhere('addressee_id', $this->id);
            })
            ->get();

        return $friendships->map(fn (Friendship $friendship): User => $friendship->otherUser($this));
    }

    /**
     * Pending friend requests sent TO this user (this user is the addressee).
     *
     * @return Collection<int, Friendship>
     */
    public function incomingRequests(): Collection
    {
        return Friendship::query()
            ->where('addressee_id', $this->id)
            ->where('status', FriendshipStatus::Pending)
            ->get();
    }

    /**
     * Pending friend requests sent BY this user (this user is the requester).
     *
     * @return Collection<int, Friendship>
     */
    public function outgoingRequests(): Collection
    {
        return Friendship::query()
            ->where('requester_id', $this->id)
            ->where('status', FriendshipStatus::Pending)
            ->get();
    }

    /**
     * The users this user has blocked.
     *
     * @return Collection<int, User>
     */
    public function blockedUsers(): Collection
    {
        return User::query()
            ->whereIn('id', UserBlock::query()->where('blocker_id', $this->id)->pluck('blocked_id'))
            ->get();
    }

    public function hasBlocked(User $user): bool
    {
        return UserBlock::query()
            ->where('blocker_id', $this->id)
            ->where('blocked_id', $user->id)
            ->exists();
    }

    public function isBlockedBy(User $user): bool
    {
        return $user->hasBlocked($this);
    }
}
