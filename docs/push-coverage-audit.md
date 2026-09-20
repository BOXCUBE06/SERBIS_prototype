# Push notification coverage audit (MDRRMO feedback C1)

Audit only, no code changed. Checked against `update-admin-vue` at commit `e72d711d`.

## 1. Coverage table

Two backend tables carry a status a resident cares about: `tbl_service_request` (one shared table for all seven services, status set `Pending / Booked / Responding / Resolved / Cancelled / Disapproved` — `ServiceRequestController::STATUSES`, `:83`) and `tbl_equipment_borrowing` (`Pending / Approved / Released / Returned / Denied / Cancelled` — base enum in `2026_06_22_042205_create_tbl_equipment_borrowing_table.php:16`, `Cancelled` added later in `2026_09_03_100000_add_cancelled_to_tbl_equipment_borrowing_status.php`). Ambulance is the only service with a second table (`tbl_ambulance_bookings`) and its own extra transitions (approve / reschedule).

| Service type | Transition | Push? | SMS? | In-app notification record? |
|---|---|---|---|---|
| Ambulance/Medical Response | Booked → Responding (**approved**, scheduled booking) | **Yes** — `approve()`, `ServiceRequestController.php:1550` | No | No |
| Ambulance/Medical Response | Booked → Disapproved (**rejected**, scheduled booking) | **Yes** — gated on `$wasBookingRejection`, `:1360-1361` | No | No |
| Ambulance/Medical Response | Booked → Booked, `scheduled_at` changed (**rescheduled**) | **Yes** — `reschedule()`, `:1632` | No | No |
| Ambulance/Medical Response | Pending → Disapproved (**rejected**, unscheduled/instant request — comment at `:1324-1326` explicitly calls this "not a booking") | **No** | No | No |
| Ambulance/Medical Response | Responding → Resolved (**completed** / trip closed) | **No** | No | No |
| Ambulance/Medical Response | any → Cancelled (resident- or staff-cancelled) | **No** | No | No |
| Relief Goods Distribution | Pending → Booked (**approved**-equivalent) | No | No | No |
| Relief Goods Distribution | Pending → Disapproved (**rejected**) | No | No | No |
| Relief Goods Distribution | Responding → Resolved (**completed**) | No | No | No |
| Relief Goods Distribution | any → Cancelled | No | No | No |
| Road Clearing | (same four transitions as Relief Goods) | No | No | No |
| Power Line Repair | (same four transitions as Relief Goods) | No | No | No |
| Debris Removal | (same four transitions as Relief Goods) | No | No | No |
| Animal Rescue | (same four transitions as Relief Goods) | No | No | No |
| Sandbagging | (same four transitions as Relief Goods) | No | No | No |
| Equipment borrowing | Pending → Approved | No | No | No |
| Equipment borrowing | Pending → Denied | No | No | No |
| Equipment borrowing | Approved → Released (**ready for pickup** / handed over) | No | No | No |
| Equipment borrowing | Released, **due tomorrow or today** (reminder, not a status transition) | **Yes** — `SendReturnDueReminders`, push only since 2026-09-21 | No — moved off SMS; no fallback | No (a miss is a system-log row, see the note below) |
| Equipment borrowing | Denied for "unavailable", stock comes back (**available again**) | **Yes** — `EquipmentAvailabilityNotifier`, push only since 2026-09-21 | No — moved off SMS; no fallback | No (a miss is a system-log row, see the note below) |
| Equipment borrowing | Released → Returned (**completed**) | No | No | No |
| Equipment borrowing | any → Cancelled | No | No | No |

**Update 2026-09-21 — push-only notices.** The equipment due-back reminder and the available-again notice no longer send a text. The row is marked reminded (`return_reminder_sent_at`, `availability_reconfirm_sent_at`) only when FCM accepts the push for at least one of the resident's devices; otherwise it stays unmarked and is retried (the next daily run, at most twice; the next restock), and an `action_type = 'reminder_not_delivered'` row is written to `tbl_system_logs` (`App\Support\ReminderFollowUp`). The admin dashboard's bell lists those as "Follow up by phone" with the resident's name and number. "Accepted" is FCM's word, not proof it was shown: a resident who switched notifications off in the phone's own settings is accepted all the same. Ambulance booking reminders still send SMS and push.

**Six of seven services get zero notification of any kind on any transition.** Ambulance gets push on 3 of its 6 transitions (the three that specifically involve a *scheduled* booking going through `approve()`/`reschedule()`, or a scheduled booking being rejected). Equipment borrowing gets exactly one notification, and it's a time-based reminder, not a status-change push — every borrowing status transition itself (approved, denied, released, returned) is silent.

No in-app notification record exists anywhere in the system — no `tbl_notifications`-equivalent table, no `Observers`/`Listeners`/`Jobs`/`Events` directories in the Laravel app (checked, none exist). The only place a resident can see past communications at all is the SMS-blast advisory feed (`SmsController::advisories()`, `:321-344`), which is unrelated to service-request or borrowing status — it only shows past text-blast broadcasts, scoped to blasts this resident actually received.

## 2. Call sites and payload shape

`Fcm::sendToDevice()` (`app/Services/Fcm.php:59`) is called from exactly three places, all in `ServiceRequestController`, all reached through one shared private method:

- `notifyResidentDevices(ServiceRequest $serviceRequest, string $body)` — `ServiceRequestController.php:1114-1123`. Looks up every `DeviceToken` row for the request's `resident_id` and calls `$this->fcm->sendToDevice($deviceToken, self::PUSH_TITLE, $body)` for each (`:1122`). Walk-in requests (`resident_id === null`) are silently skipped (`:1116-1118`) — there's no account to push to.
- Called from `update()` at `:1361` — rejection body, only when `$wasBookingRejection` is true.
- Called from `approve()` at `:1550` — approval body.
- Called from `reschedule()` at `:1632` — reschedule body.

The three body-builder methods (`approvalPushBody`, `rejectionPushBody`, `reschedulePushBody`, `:1131-1148`) are also private to this controller.

`self::PUSH_TITLE = 'SERBIS'` (`:1103`) is the only title ever sent — every push looks identical in the notification shade except for its body text.

**Payload shape** — `Fcm::post()`, `app/Services/Fcm.php:91-107`:

```php
Http::withToken($this->accessToken())
    ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
        ],
    ]);
```

This is a plain FCM **notification message** — no `data` payload, no `click_action`, no `request_id` or any identifier the app could use to deep-link into the specific request when the notification is tapped. It's also why this shape reaches a closed app automatically (Android's system tray renders `notification` messages itself, with no app code required) — but it also means tapping the notification can only ever open the app to wherever it opens by default, never to the request it's about.

## 3. Shared helper — exists, but not shared

`notifyResidentDevices()` is a real, working helper for "look up a resident's devices and push a body to all of them" — but it's a **private method on `ServiceRequestController`**, not a method on `Fcm` itself and not a standalone service class. `EquipmentBorrowingController` cannot call it without either:

- duplicating the same device-token lookup + loop inline (what `ServiceRequestController` already does, just copy-pasted into a second controller), or
- extracting it — most naturally onto `Fcm` itself, as something like `Fcm::notifyResident(int $residentId, string $title, string $body)`, so both controllers (and any future one) call one real shared implementation instead of two copies that can drift.

The three *body-composing* methods (`approvalPushBody` etc.) are ambulance-specific by content and wouldn't move — only the "send to every device this resident owns" plumbing is what's reusable.

## 4. Device tokens — NOT ambulance-scoped, contrary to the concern in the task

Checked specifically: **device tokens are registered for every resident on login and app start, independent of whether they've ever booked an ambulance.** `Mobile/lib/main.dart` calls `registerDeviceToken(_api)` (with `listenForTokenRefresh`) from three places:

- `:163-164` and `:189-190` — both in the app's startup/session-restore path.
- `:233-234` — after a fresh login.

None of these three call sites are inside any ambulance-specific code path. `DeviceTokenController::store()` (`app/Http/Controllers/DeviceTokenController.php:12-35`) accepts a token from any authenticated resident and upserts it — there's no ambulance gate on the backend side either.

**Conclusion: the other six services do have somewhere to send to.** A resident who has only ever filed a Road Clearing report already has a `tbl_device_tokens` row, the same as one who has booked an ambulance. The gap identified in section 1 is entirely that the *call sites* don't exist for those services — not that the underlying delivery mechanism has nothing to target. Wiring C1 for the other six services requires no device-token work at all, only calling the (extracted) notify helper from the right places in `EquipmentBorrowingController` and from the non-ambulance branches of `ServiceRequestController::update()`.

## 5. Notifier icon / unread badge — local-only decoration, not a real unread count

Searched the mobile app for any unread-notification concept: none exists, server-side or local.

- `IconBadge` and `StatusBadge` (`Mobile/lib/widgets/shared_widgets.dart:323,348`) are generic decorative widgets — a small colored circle behind an icon, or a status-colored pill (e.g. "Pending", "Approved"). They render a fixed icon or the request's own status string; neither carries a count and neither has anything to do with notifications. They're used throughout the app (dashboard cards, track screen, library cards) purely as visual chrome.
- There is no `FirebaseMessaging.instance.onMessage.listen(...)` handler anywhere in the codebase — a push that arrives while the app is **open** (foreground) has no custom handling at all. (Foreground FCM notification messages are not auto-displayed by the OS the way background/closed ones are, so a push landing while a resident has the app open today most likely shows nothing visible — this is a second, separate gap from the "closed app" one C1 named, worth flagging even though it wasn't explicitly asked about.)
- No badge package (`flutter_app_badger` or equivalent) is in `Mobile/pubspec.yaml`, and no code anywhere sets an app-icon badge count.

There is nothing to build "read from a server-side count" — an unread count would need to be invented from scratch: a schema for what counts as unread (which, per section 1, doesn't exist as a distinct concept since there's no notification record at all), an API to read/clear it, and the actual badge-setting code on the Flutter side.
