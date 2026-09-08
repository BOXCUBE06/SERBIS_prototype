<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'last_name', 'role', 'status', 'email_address', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, TracksHistory;

    /**
     * Staff accounts are created and removed from the panel now (audit #29), so
     * who did it is worth a row in the log — an account appearing or vanishing
     * is the kind of change nobody remembers making.
     *
     * `password` and `remember_token` are in the trait's default ignore list;
     * they are named again here because this list replaces that default rather
     * than adding to it, and dropping either would write a password hash into
     * a table the Logs page renders.
     */
    protected $ignoreLogging = ['created_at', 'updated_at', 'password', 'remember_token'];

    /**
     * Closed accounts are the ones that say so, rather than the ones that fail
     * to say Active.
     *
     * This is deliberately not fail-closed. `status` arrived long after this
     * table did, and the recovery path when an office locks itself out is still
     * a hand-written INSERT — which names its columns and would leave this one
     * null. Requiring the string 'Active' would turn every such row into an
     * account that exists, holds the right password, and cannot sign in.
     * Deactivation is an action someone took; absence of it is not.
     */
    public function isDeactivated(): bool
    {
        return strtolower((string) $this->status) === 'inactive';
    }

    /**
     * The one definition of what an admin is. `role` is a bare varchar with no
     * constraint, and the two writers disagree on case — AdminController and
     * AdminSeeder both store 'Admin', while every test fixture creates 'admin'.
     * A case-sensitive comparison is therefore true under test and false in
     * production, which is how ServiceRequestController::index() came to send
     * an admin down the resident branch and query tbl_service_request by
     * admin_id. Comparing case-insensitively in one place is what stops the
     * next caller reintroducing that.
     */
    public function isAdmin(): bool
    {
        return strtolower($this->role ?? '') === 'admin';
    }

    protected $table = 'tbl_user';

    protected $primaryKey = 'admin_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Get the name of the password attribute for the user.
     *
     * @return string
     */
    public function getAuthPasswordName()
    {
        return 'password';
    }
}
