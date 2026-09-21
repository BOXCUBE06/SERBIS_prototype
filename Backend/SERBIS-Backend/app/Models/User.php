<?php

namespace App\Models;

use App\Support\AdminSections;
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

    /**
     * A super admin is a flag on an admin, not a value of `role`: isAdmin()
     * matches 'admin' exactly, and a client-settable role is how an account
     * ends up locked out of the panel it was made for (AdminController::store).
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * The sections this account may open, in sidebar order.
     *
     * A super admin has all of them, Staff Accounts included. Anyone else has
     * the listed ones, or every assignable one when the list is NULL — the
     * value every account that existed before permissions did keeps, so
     * deploying them changes nothing for anyone. Staff Accounts is never
     * assignable (AdminSections::ASSIGNABLE) and unknown keys are ignored, so a
     * stale or hand-edited list cannot grant more than the panel offers.
     *
     * @return list<string>
     */
    public function allowedSections(): array
    {
        if ($this->isSuperAdmin()) {
            return AdminSections::ALL;
        }

        $granted = $this->permissions;

        if (! is_array($granted)) {
            return AdminSections::ASSIGNABLE;
        }

        return array_values(array_intersect(AdminSections::ASSIGNABLE, $granted));
    }

    public function canAccess(string $section): bool
    {
        return in_array($section, $this->allowedSections(), true);
    }

    /**
     * Super admins who can still sign in. The last one may not be demoted,
     * closed or deleted: they are the only account that can change anyone's
     * access, so losing them leaves the panel with no way to fix it short of
     * `staff:make-super-admin` on the server.
     */
    public static function activeSuperAdminCount(): int
    {
        return static::where('is_super_admin', true)
            ->where(function ($query) {
                $query->whereNull('status')->orWhereRaw('LOWER(status) != ?', ['inactive']);
            })
            ->count();
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
            // Not in #[Fillable]: only the reset paths set it, one attribute
            // at a time, so no request payload can clear or raise it.
            'must_change_password' => 'boolean',
            // Also not in #[Fillable], for the same reason and a worse one: a
            // mass-assignable flag is a request that promotes itself.
            // AdminController::updatePermissions and staff:make-super-admin are
            // the only writers.
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
        ];
    }

    /**
     * A one-time password for a staff account whose owner cannot sign in.
     * Shown once to whoever resets it, so it avoids characters that read alike
     * (0/O, 1/l/I), and always satisfies the panel's own password rule
     * (8+ characters, upper and lower case, a digit).
     */
    public static function generateTemporaryPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all = $upper.$lower.$digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with random_int, so the three guaranteed characters do
        // not always sit at the front.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
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
