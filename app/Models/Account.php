<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A login (table `accounts`). The only Eloquent model: game data is read and written with the query
 * builder (DESIGN.md §11.2). One account owns exactly one character.
 *
 * @property int $id
 * @property string $username
 * @property ?string $email
 * @property string $password_hash
 * @property bool $is_admin
 */
class Account extends Authenticatable
{
    public const UPDATED_AT = null;

    protected $table = 'accounts';

    protected $fillable = ['username', 'email', 'password_hash', 'is_admin'];

    protected $hidden = ['password_hash'];

    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }
}
