<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// MERGE the relationships/casts below into your existing App\Models\User.
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'job_title', 'is_available', 'last_assigned_at'])
            ->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo { return $this->belongsTo(Workspace::class, 'current_workspace_id'); }
    public function assignedContacts(): HasMany { return $this->hasMany(Contact::class, 'assigned_user_id'); }
    public function assignedConversations(): HasMany { return $this->hasMany(Conversation::class, 'assigned_user_id'); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }
}
