# Local volume data

Fills the local database (`serbis_test_db`) with realistic data for the admin panel:
about 300 residents, 1,000 service requests and 300 equipment loans over 24 months,
50 text blasts with their recipients, and the info-material PDFs. Local only.

```
php artisan serbis:reseed-requests --volume
```

Add `--force` to skip the confirmation, `--seed=N` for different (still repeatable)
data, `--scale=0.1` for a tenth of the size. Takes a few minutes at full size.

**This deletes** every service request, ambulance booking, trip record, equipment
loan and their audit-log rows, then rebuilds them. Residents, staff, barangays,
services, equipment, vehicles and responders are kept. The first run adds residents,
five staff and the blasts; later runs add none of those again.

It refuses to run unless `APP_ENV` is not production, the database is `serbis_test_db`
or `serbis_phpunit`, **and `SERBIS_SMS_FAKE=true`**. The seeded phone numbers are
valid Philippine mobiles (`+63999xxxxxxx`) and some belong to real people, so no text
may be able to leave this machine.

## What is in it

| Data | Count | Notes |
|---|---|---|
| Residents | 300 | ~265 heads of the family, 20 barangay accounts (one per barangay), 15 organizations. Registered over 24 months, 4% more each month; a few barangays hold most of them. ~12% Inactive, ~45% with no email, ~8% phone unverified. |
| Service requests | 1,000 | 850 service, 150 ambulance. |
| Equipment loans | 300 | About 5% for equipment not in the catalogue. |
| Staff | +5 | See below. |
| Text blasts | ~50 | To one barangay each, reaching only opted-in residents already registered at the time. |
| Info materials | 4 | Real PDFs on the `public` disk under `storage/app/public/info_materials` (not in git). |

A few residents file most requests and about a quarter file none. Filings dip at
weekends and rise 40% from July to October. Every request is dated after its
resident registered.

**Open statuses are only in the last 28 days.** Anything older is final.

| Requests | Resolved | Cancelled | Disapproved | Pending | Booked | Responding |
|---|---|---|---|---|---|---|
| 1,000 | 790 | 95 | 89 | 16 | 6 | 4 |

Loans: Returned 217, Denied 30, Cancelled 22, Pending 8, Approved 6, Released 12,
Overdue 5. Equipment stock, vehicle and responder status are reconciled afterwards
(`available = total - Released`, a unit is Dispatched only while a Responding request
holds it).

## Logins

Staff (admin panel, username + password):

| Username | Password | Access |
|---|---|---|
| `admin` | `password123` | Super admin |
| `maria.pascual` | `password123` | Everything |
| `ramil.cabacungan` | `password123` | Everything |
| `lorna.agbayani` | `Passw0rd!123` | Everything |
| `jonas.tumaneng` | `Passw0rd!123` | Requests and ambulance only |
| `evelyn.rabago` | `Passw0rd!123` | Borrowings, inventory, residents |
| `mark.dumlao` | `Passw0rd!123` | **Deactivated**: sign-in is refused |
| `grace.santos` | `Passw0rd!123` | Must change the password at first sign-in |

The first three are created by `DemoSeeder`/`AdminSeeder`; their passwords are whatever
was last set, these are the seeded defaults.

Residents (mobile app): every seeded resident has the password `Passw0rd!123`, and
signs in with the phone number, for example `+639991000000` (the oldest account). Emails
look like `mdelacruz12@seed.serbis.test`.

## The OTP code

Staff sign-in asks for a six-digit code (`ADMIN_MFA_ENABLED=true`). Nothing is texted:
`SERBIS_SMS_FAKE=true` makes `SkySmsGateway` return a fake success, and the code is not
logged. Type **`555555`**, the `SERBIS_OTP_BYPASS_CODE` in `.env`. It works for staff and
residents, and every use is written to the audit log.

The bypass cannot work outside local: `otpBypassMatches()` also requires
`APP_ENV=local`, and `AppServiceProvider::assertOtpBypassIsLocalOnly()` stops the app
from booting at all when the variable is set in any other environment. The one way
around it is a server whose `APP_ENV` is wrongly set to `local`.

`SKYSMS_API_KEY` is blank in the local `.env` (the old value is kept on a commented line
above it), so even with `SERBIS_SMS_FAKE` removed nothing could send. Blasts, OTPs and the
return reminders still return a fake success; only the SMS credit figure in the panel
header reads "no key configured".

## Other seeders

`DemoSeeder` and `DemoAccountsSeeder` are unchanged and use other phone numbers and
emails (`+63917…`, `@serbis.test`). `DemoAccountsSeeder` can be run after this and
rebuilds its own rows. `DemoSeeder` is meant for `migrate:fresh` and inserts its two
staff without checking, so it cannot run on a database that already has them, with or
without this seeder.

`DevVolumeSeedTest` runs the command at a tenth of the size and checks the invariants
above (dates in order, open statuses recent, stock, blasts, re-running).
