<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeChatMessage extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['read_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function senderUser() { return $this->belongsTo(User::class, 'sender_user_id'); }

    /**
     * 29-Sep-2026 (Ejaz): chat history lives only for the agent session. Called on sign-in
     * (register-device), agent sign-out and post-shift auto sign-out — clearing at sign-in
     * too means any other way a session ends (crash, admin revoke) still starts clean.
     */
    public static function clearFor(int $employeeId): void
    {
        static::withoutGlobalScopes()->where('employee_id', $employeeId)->delete();
    }

    /** Shape shared by the admin console and the agent. */
    public function toChat(): array
    {
        return [
            'id'         => $this->id,
            'sender'     => $this->sender,
            'name'       => $this->sender === 'ADMIN' ? ($this->senderUser?->name ?: 'Admin') : null,
            // 30-Sep-2026 (Ejaz): the agent shows who wrote it — "Ravi Teja · Team Leader".
            'role'       => $this->sender === 'ADMIN' ? static::roleLabel($this->senderUser) : null,
            'body'       => $this->body,
            'at'         => $this->created_at?->toIso8601String(),
            'read'       => $this->read_at !== null,
            'delivered'  => $this->delivered_at !== null || $this->read_at !== null,
        ];
    }

    /** The sender's role as the console names it (custom roles keep their own name). */
    public static function roleLabel(?User $user): ?string
    {
        $role = $user?->role;
        if (! $role) return null;
        $name = trim((string) $role->name);
        return $name !== '' ? $name : ucwords(strtolower(str_replace('_', ' ', (string) $role->slug)));
    }
}
