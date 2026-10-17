# SERBIS live-trial runbook

Covers every RTM row marked Live or Manual in `audits/rtm-draft.csv`. Run it against the deployed
system (Railway API, Vercel panel, a real Android phone) after the merge to `main` on 2026-10-17
(`82f8c383`). Nothing here runs automatically.

> Reconstructed from the RTM. The original "live-trial runbook" prompt was not in this session, so
> if it asked for a different layout or scope, send it and this file will be redone.

## Ground rules

- **Real SMS costs credits** (SkySMS, no sandbox) and a link in a message costs 10-50 credits and is not
  delivered. Send only to the trial phones listed below, never to a barangay list. Keep messages short, no links.
- Use **trial accounts only**. Do not edit, deactivate or text real residents. Create them first, delete them last.
- Write the date, time (Manila), build and result next to every step. A step without evidence is not done.
- Anything that fails goes into `test-results/bug-log.csv` with the TC id, then stop that block and move on.
- Admin copy says "Head of the Family", never "resident".

## Before you start (15 min)

| Need | Detail |
| --- | --- |
| Trial phones | 2 Android (one old, one current) and 1 iPhone if available. Each has a SIM that receives SMS. Note the numbers. |
| Trial accounts | 1 Head of the Family, 1 organization (left Inactive), 1 barangay account. 1 staff account with a limited section list, 1 super admin. |
| Credits | Read the balance on the Text Blast page. Need at least 30. Write it down. |
| Deploy check | Railway `serbis-api` deployment for `82f8c383` is Success; Vercel is Success (both reported by GitHub for that commit). CI (PHPUnit, Flutter) green. |
| Build | Release APK built with `--dart-define=API_BASE_URL=https://<api>/api` (`https://` is required for release). |
| Browsers | Chrome, Edge, Firefox, Safari at 1366x768 and 1920x1080. |

## A. Platform (NFR-OPS-01, NFR-SEC-05, NFR-OPS-03)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| A1 | TC-NFR-31 | Open the Railway deployment log for `82f8c383`. | Entrypoint passed its checks, `migrate --force` ran with nothing pending (this merge has no migrations), service healthy, no restart loop. |
| A2 | TC-NFR-31 | `GET <api>/up`. | 200. |
| A3 | TC-NFR-21 | Open the panel from the Vercel URL, watch the Network tab. | All calls go to the production API over `https`; no mixed-content or CORS errors; login works. |
| A4 | TC-NFR-21 | Look at response headers on `/api/me`. | `Strict-Transport-Security` present; no `X-Powered-By` PHP version; HTTP redirects to HTTPS. |
| A5 | TC-NFR-33 | Upload a valid ID as the trial Head of the Family (any request form). | File saved; the URL for it is only an API route that needs a login; the same URL with no token gets 401; the Supabase bucket is not publicly listable. |
| A6 | TC-ADM-UI-06 | From a browser on another origin, call the API without the panel origin. | Refused by CORS. |

## B. Sign-in codes by SMS (FR-MOB-10, NFR-MFA-04)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| B1 | TC-MOB-10 | Sign up the trial Head of the Family on a phone. | Code SMS arrives in under 30 s, text names SERBIS and "expires in 15 minutes"; entering it signs in. |
| B2 | TC-MOB-10 | Log out, log in again. | Code arrives ("expires in 5 minutes"); wrong code gives an inline error; 5 wrong codes lock the attempt. |
| B3 | TC-MOB-10 | Request Resend inside the cooldown. | Refused with the wait shown; after the wait a new code works. |
| B4 | TC-NFR-15 | Super admin: set staff phone numbers (Staff Accounts), then **only in a maintenance window** set `ADMIN_MFA_ENABLED=true` on Railway. Sign in as staff. | Code reaches the staff phone in under 30 s; verify returns a token. A staff member with no number sees "Ask a super admin to add your mobile number". Set the flag back to `false` after unless MFA is meant to stay on. |

**SQL to confirm:** B1 `SELECT resident_id, phone_number, phone_verified_at FROM tbl_residents WHERE phone_number = '+639XXXXXXXXX';` (`phone_verified_at` is set). B4 `SELECT admin_id, username, phone_number FROM tbl_user WHERE phone_number IS NOT NULL;`

Do B4 last in the SMS blocks and have the flag value written down before changing it: a wrong number locks that person out.

## C. Text blast (FR-ADM-39)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| C1 | TC-ADM-39 | Put the trial phones' accounts in a test barangay and Activate them. Send a blast of one short line, no link, to only that barangay. Enter the blast code. | Page says Queued, never Sent. Credits drop by the recipient count. |
| C2 | TC-ADM-39 | Wait 1 min, press Check status. | Recipients turn Pending then Sent; the phones receive the text. If a message stays Pending past 10 min, log it: this is the open SkySMS issue from 2026-09-20. |
| C3 | TC-ADM-39 | Look at the app's advisories on a trial phone. | The blast appears; a resident outside the barangay does not see it. |
| C4 | TC-ADM-39 | Type a message with a link. | Refused locally before any send; credits unchanged. |

**SQL to confirm:** `SELECT sms_log_id, target_area_id, status, created_at FROM tbl_sms_logs ORDER BY sms_log_id DESC LIMIT 1;` gives `@log_ids`.
C1: query (a), plus `SELECT status, COUNT(*) FROM tbl_recipients WHERE sms_log_id = @log_ids GROUP BY status;` (rows exist, `Queued`).
C2: queries (b) and (c) after Check status. C4: the `LIMIT 1` query shows no new row.

Record the credit balance before and after. Expected cost is one credit per recipient per segment.

## D. Push notifications (FR-MOB-28)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| D1 | TC-MOB-28 | Log in on a phone with notifications allowed. | A device token is registered (a row in `tbl_device_tokens` for that resident). |
| D2 | TC-MOB-28 | As the Head of the Family file a road-clearing request. As staff: Book it, then Responding with a responder assigned. | A push at each step, naming the service. |
| D3 | TC-MOB-28 | As staff cancel a Pending request of the trial resident. | Push "was cancelled by MDRRMO" (new in this release). Resident cancelling their own sends none. |
| D4 | TC-MOB-28 | Turn app notifications off in phone settings, repeat one status change. | The server still reports success; nothing shows on the phone. This is the known limitation BUG-003: staff get no warning when a push reaches nobody. |
| D5 | TC-MOB-28 | Log out. | The token is removed; no push afterwards. |

**SQL to confirm:** D1 and D5 `SELECT resident_id, platform, last_seen_at FROM tbl_device_tokens WHERE resident_id = <trial id>;` has a row after login, none after logout.
D2/D3 `SELECT request_id, status, updated_at FROM tbl_service_request WHERE resident_id = <trial id> ORDER BY request_id DESC LIMIT 5;`

## E. Release build on real devices (FR-MOB-37, NFR-COMP-02, NFR-COMP-03)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| E1 | TC-MOB-37 | Install the release APK on both Androids (and iPhone if built). | Opens to login, not the red configuration screen; fonts correct; no cleartext errors. |
| E2 | TC-NFR-23 | Walk the Head of the Family through Home, Ambulance booking (all steps to review), a Service request, Borrow, Track, Library, Hotlines, Profile. | Every screen loads; Back/Next footer pinned; targets easy to tap; text readable at the phone's largest font size. |
| E3 | TC-NFR-23 | Repeat Home, Services and Track with the organization account (Inactive), then after activation; and the barangay account. | Organization sees the waiting state, cannot file; after activation can. Services shown match Service Audience. |
| E4 | TC-NFR-23 | Switch to Filipino. | Headings sentence case; no English fallbacks on the screens above. |
| E5 | TC-NFR-23 | Airplane mode on Home and Track. | Offline banner; cached lists stay; actions fail with a clear message, no crash. |
| E6 | TC-NFR-24 | Open the Flutter web build on a tablet, or rotate a tablet emulator. | Content stays at phone width (600), centred. |

**SQL to confirm:** E3 `SELECT resident_id, account_type, status FROM tbl_residents WHERE resident_id IN (<trial ids>);` (organization `Inactive`, then `Active` after activation).

## F. Admin panel by hand (FR-ADM-48 to 53)

Run each group in Chrome first, then re-check login, Dashboard, Resident Requests and Text Blast in Edge, Firefox and Safari (NFR-COMP-01, TC-NFR-22). Look for layout breaks, console errors, failed requests, wrong sidebar items, and the date and time picker, which was changed to a dialog in this release and has not been checked in a browser.

| Group | TC | Pages | Check |
| --- | --- | --- | --- |
| Overview | TC-ADM-UI-01 | Dashboard, Analytics | Figures load, queue tabs, bell, charts and the print report render; barangay filter "All N barangays" chip. |
| Requests | TC-ADM-UI-02 | Resident Requests, Ambulance Dispatch, Equipment Borrowing | Approve, disapprove with reason, reschedule, assign responders through the new crew picker, dispatch an ambulance, print and CSV export. A booked slot that is full is blocked in the schedule. |
| Resources | TC-ADM-UI-03 | Vehicles, Responders, Resource Management, Procurement | Add, edit, photo upload and remove, delete guards. |
| Community | TC-ADM-UI-04 | Accounts, Documents, Text Blast | Activate the organization; upload and delete a PDF and confirm it leaves the app library; blast form and Recent blasts card. |
| Configuration and System | TC-ADM-UI-05 | Services, Audience, Service Vehicles, Hotlines, Staff, Logs | Edits save; limited staff see only their sections and `/staff` sends them away; super admin sees Staff. |
| Production link | TC-ADM-UI-06 | all | Same session as A3. |

**Bulk Activate/Deactivate (FR-ADM-54, TC-ADM-54).** In Accounts select Pending, Active and Inactive accounts together.
Activate lists Pending names under "Will be approved (pending review)" and stays disabled until APPROVE is typed; already-active rows are skipped. Deactivate takes Pending too. Check the Result panel
numbers match what changed, then confirm a deactivated trial phone is logged out of the app.

**SQL to confirm:** `SELECT status, COUNT(*) FROM tbl_residents WHERE resident_id IN (<trial ids>) GROUP BY status;` matches the Result panel.
For the other groups, `SELECT log_id, action_type, description, created_at FROM tbl_system_logs ORDER BY log_id DESC LIMIT 10;` shows the edit just made.

## G. Performance and operations (NFR-PERF-03, NFR-PERF-04, NFR-OPS-02, NFR-OPS-04)

| Step | TC | Do | Expect |
| --- | --- | --- | --- |
| G1 | TC-NFR-03/04 | Time Dashboard, Resident Requests list, Accounts list and Analytics from the panel (browser Network tab, at least 20 loads each so a p95 means something, standard network conditions, no throttling tricks). | p95 under 3 s for data retrieval (the manuscript's benchmark). Record median and p95 per page. |
| G2 | TC-NFR-04 | Look at Railway metrics (CPU, memory, DB connections) during G1 and during one blast. | No restarts, no saturation. |
| G3 | TC-NFR-04 | First request after the API has been idle. | Note cold-start time. |
| G4 | TC-NFR-32 | Next morning after 00:00 UTC, open the Railway Cron service run. | Both commands ran (`serbis:send-return-reminders`, `sanctum:prune-expired --hours=24`) with exit 0. To prove reminders send: a trial borrowing due tomorrow and a trial ambulance booking within the reminder window, then see the push (and the booking SMS). |

**SQL to confirm:** G4 `SELECT log_id, action_type, created_at FROM tbl_system_logs WHERE action_type LIKE '%reminder%' ORDER BY log_id DESC LIMIT 10;` and `SELECT COUNT(*) FROM personal_access_tokens WHERE created_at < NOW() - INTERVAL 24 HOUR;` after the prune. G5 on the restored copy: `SELECT COUNT(*) FROM tbl_residents;`, compare with production. G1-G3 are timings with no table to read.
| G5 | TC-NFR-34 | In Railway, take or locate the latest MySQL backup. Restore it into a throwaway service, never into production, and open a few rows. | Backup exists, restores, data readable. Delete the throwaway. |

## SQL checks (read-only)

Run in a MySQL client against the production database (Railway shell or a read-only user). `SELECT` only; nothing here writes.
Set the variables first. `@log_ids` and `@barangay_ids` are comma lists, so one query covers a blast to several barangays.

```sql
SET @log_ids      = '1';   -- tbl_sms_logs.sms_log_id of the blast(s) under test
SET @barangay_ids = '1';   -- tbl_barangay.barangay_id of the selected barangay(s)
SET @n_minutes    = 10;    -- on-time window in minutes; default 10, change freely
```

**(a) Targeting, goal 98%.** Reached = distinct eligible Heads of the Family with a recipient row in the blast.
Eligible = the filter the send itself uses (`SmsController::resolveRecipients`): Head of the Family, Active, opted in, with a phone number.
A phone shared by two households is texted once, so the figure can sit just under 100 when numbers repeat.

```sql
SELECT e.eligible,
       r.reached,
       ROUND(100 * r.reached / NULLIF(e.eligible, 0), 2) AS targeting_pct
FROM (SELECT COUNT(DISTINCT phone_number) AS eligible
      FROM tbl_residents
      WHERE FIND_IN_SET(barangay_id, @barangay_ids)
        AND account_type = 'head_of_family'
        AND status = 'Active'
        AND sms_opt_in = 1
        AND phone_number IS NOT NULL AND phone_number <> '') e,
     (SELECT COUNT(DISTINCT res.phone_number) AS reached
      FROM tbl_recipients rc
      JOIN tbl_residents res ON res.resident_id = rc.resident_id
      WHERE FIND_IN_SET(rc.sms_log_id, @log_ids)
        AND FIND_IN_SET(res.barangay_id, @barangay_ids)
        AND res.account_type = 'head_of_family'
        AND res.status = 'Active'
        AND res.sms_opt_in = 1) r;
```

Run it right after the send: a Head of the Family deactivated or opted out afterwards lowers the figure without the blast being wrong.
Who was missed:

```sql
SELECT res.resident_id, res.phone_number
FROM tbl_residents res
LEFT JOIN tbl_recipients rc
       ON rc.resident_id = res.resident_id AND FIND_IN_SET(rc.sms_log_id, @log_ids)
WHERE FIND_IN_SET(res.barangay_id, @barangay_ids)
  AND res.account_type = 'head_of_family' AND res.status = 'Active' AND res.sms_opt_in = 1
  AND res.phone_number IS NOT NULL AND res.phone_number <> ''
  AND rc.recipient_id IS NULL;
```

**(b) Delivery rate per blast.** Statuses: `Queued` (taken by SkySMS, not confirmed), `Pending`, `Sent` (confirmed delivered),
`Failed`, `Unconfirmed` (timed out), `Other`. Only `Sent` counts as delivered. Press Check status in the panel first, or rows stay `Queued` and the rate reads low.

```sql
SELECT rc.sms_log_id,
       COUNT(*)                                           AS recipients,
       SUM(rc.status = 'Sent')                            AS delivered,
       SUM(rc.status IN ('Queued','Pending'))             AS still_waiting,
       SUM(rc.status IN ('Failed','Unconfirmed','Other')) AS not_delivered,
       ROUND(100 * SUM(rc.status = 'Sent') / COUNT(*), 2) AS delivery_pct
FROM tbl_recipients rc
WHERE FIND_IN_SET(rc.sms_log_id, @log_ids)
GROUP BY rc.sms_log_id;
```

**(c) On-time rate.** On time = `Sent` and `tbl_recipients.updated_at` (when the status last changed) within `@n_minutes` of `tbl_sms_logs.created_at`.
That timestamp is when the panel recorded the change, so it is only as precise as when Check status was pressed; press it every minute during the trial.

```sql
SELECT l.sms_log_id,
       COUNT(*) AS recipients,
       SUM(rc.status = 'Sent'
           AND TIMESTAMPDIFF(SECOND, l.created_at, rc.updated_at) <= @n_minutes * 60) AS on_time,
       ROUND(100 * SUM(rc.status = 'Sent'
           AND TIMESTAMPDIFF(SECOND, l.created_at, rc.updated_at) <= @n_minutes * 60)
           / COUNT(*), 2) AS on_time_pct
FROM tbl_sms_logs l
JOIN tbl_recipients rc ON rc.sms_log_id = l.sms_log_id
WHERE FIND_IN_SET(l.sms_log_id, @log_ids)
GROUP BY l.sms_log_id;
```

## Clean-up

1. Delete the trial accounts, requests, borrowings, the test PDF and the test barangay changes.
2. Set `ADMIN_MFA_ENABLED` back to its intended value.
3. Remove the throwaway restore service.
4. Record the credit balance at the end and the total spent.

## Sign-off sheet

| RTM row | TC | Pass / Fail | Date | Tester | Evidence |
| --- | --- | --- | --- | --- | --- |
| FR-ADM-39 | TC-ADM-39 | | | | |
| FR-ADM-48 to 53 | TC-ADM-UI-01 to 06 | | | | |
| FR-ADM-54 | TC-ADM-54 | | | | |
| FR-MOB-10 | TC-MOB-10 | | | | |
| FR-MOB-28 | TC-MOB-28 | | | | |
| FR-MOB-37 | TC-MOB-37 | | | | |
| NFR-PERF-03, 04 | TC-NFR-03, 04 | | | | |
| NFR-MFA-04 | TC-NFR-15 | | | | |
| NFR-SEC-05 | TC-NFR-21 | | | | |
| NFR-COMP-01 | TC-NFR-22 | | | | |
| NFR-COMP-02, 03 | TC-NFR-23, 24 | | | | |
| NFR-OPS-01 | TC-NFR-31 | | | | |
| NFR-OPS-02 | TC-NFR-32 | | | | |
| NFR-OPS-03 | TC-NFR-33 | | | | |
| NFR-OPS-04 | TC-NFR-34 | | | | |
