<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DemoAccount;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_operator may open the operator report; set only by the seeder, never fillable
 * @property DemoAccount|null $demo_account the demo account this user is; set only by the seeder, never fillable
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @mixin IdeHelperUser
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The database default, repeated here because a model that was created in this process, as
     * in a test, does not carry the column until it is loaded again, and the gate must still get
     * a boolean.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_operator' => false,
    ];

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * Demo accounts are shared by everyone who uses demo sign-in, so their profile is fixed.
     */
    public function isDemoAccount(): bool
    {
        return $this->demo_account !== null;
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
            'is_operator' => 'boolean',
            'demo_account' => DemoAccount::class,
        ];
    }
}
