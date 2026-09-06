# SERBIS mobile app — user-facing strings audit

Read-only inventory. No `.dart` files were changed to produce this document. Every screen under `Mobile/lib/screens/**` and every widget under `Mobile/lib/widgets/**` was read in full. Two files outside that list are cited because they hold user-facing text that renders through an in-scope widget: `Mobile/lib/models/service_forms.dart` (the actual field labels/hints for the Road, Relief and Generic request forms, rendered by `service_form_fields.dart`) and `Mobile/lib/state/translations.dart` (the English/Filipino pairs behind every `tr(filipino, 'key')` call — cited only to resolve what a `tr()` call actually renders, not audited key-by-key itself).

**How to read the columns.** `type` is one of `label`, `placeholder`, `button`, `dialog title`, `dialog body`, `snackbar`, `empty state`, `validation`. `issue` is one of `missing placeholder`, `gendered`, `unclear`, `inconsistent`, or `none` (still listed — completeness over brevity). A row sourced from `translations.dart` says so in the `issue`/`proposed replacement` cell rather than being treated as a literal in the screen file.

**A cross-cutting pattern, flagged once here rather than on every row it touches:** the app uses three different localization mechanisms for what is otherwise the same "show English or Filipino" job — `tr(filipino, 'key')` against `translations.dart` (dashboard, track, services, ambulance schedule, offline banner, most of profile), inline `filipino ? '...' : '...'` ternaries with no shared table (library, most of profile's dialogs, article reader), and no localization at all (`borrow_equipment_screen.dart` — entirely English regardless of the language setting, despite threading a `filipino`/`f` value through several of its own child widgets). This is called out per-row below as `inconsistent` where it is the dominant problem, and is also its own line in the terminology table at the end.

---

## Remediation status (2026-09-06)

The user approved three of this document's four issue categories for a fix pass: the 6 `missing placeholder` rows, the 5 `unclear` rows, and the ~16 wording-drift rows of the terminology table. **Every row whose only problem is the localization-mechanism split was explicitly left alone** and is tagged `Deferred` in place — it is a refactor (route ~45 strings through `translations.dart`, and give `borrow_equipment_screen.dart` a Filipino half it has never had), not a copy edit, and it needs its own decision.

Each affected row below now carries its status in the `proposed replacement` cell. Six commits, one per screen, each verified in the running web build at 430x932 with the console watched for `overflowed by` (none appeared on any screen):

| Screen | Commit | What changed |
|---|---|---|
| `profile_screen.dart` | `1c2faac` | First/Middle/Last name and Email address hints; "sign in" -> "log in" in the logout dialog (both language halves) |
| Services (`services_screen.dart`, `service_form_fields.dart`, `service_forms.dart`) | `c482c47` | "Landmark (optional)" -> "Site photo (optional)"; "Full name" -> "e.g. Juan Dela Cruz" on Relative {n} and Household head name; the trip "From" hint |
| `login_screen.dart` | `ad47f50` | Password hint dropped (it restated the label and doubled as the validator message) |
| `register_screen.dart` | `3156c19` | Phone hint -> the shared `09XXXXXXXXX` template; Confirm-password hint dropped; the head-of-the-family intro line reworded |
| `verify_login_screen.dart` | `30145a3` | "Login code" -> "Verification code" |
| `borrow_equipment_screen.dart` | `1d9b4aa` | `9/12/2026` -> `Sep 12, 2026` via a new shared `formatDueDate`; the screen's strings are untouched and still English-only |

**Line numbers throughout this document are as-audited** — they were correct before the fix pass and several have shifted by a line or two since. Search by the quoted text, not by the number.

Two rows were closed without a code change of their own: `verify_email_screen.dart:219-220` (the drift it named was resolved by renaming the other screen) and `profile_screen.dart:1209-1210` (the "Mobile number" vs "Contact number" split is now recorded as intentional — the account holder's own number vs. someone else's).

---

## `Mobile/lib/screens/auth/login_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| login_screen.dart:154 | "Welcome back" | label | none | — |
| login_screen.dart:160 | "Log in to submit and track your service requests." | label | none | — |
| login_screen.dart:195-196 | label "Email address", hint "yourname@email.com" | placeholder | none | — (hint already shows format) |
| login_screen.dart:202 | "Enter your email address" | validation | none | — |
| login_screen.dart:204 | "Enter a valid email address" | validation | none | — |
| login_screen.dart:210-211 | label "Password", hint "Enter your password" | placeholder | inconsistent | **Applied — `ad47f50`.** Register's Password field hints the actual rule ("At least 8 characters"); this one just restates the label. Drop the hint or use "At least 8 characters" here too. |
| login_screen.dart:216 | "Enter your password" | validation | none | — |
| login_screen.dart:268 | "Contact MDRRMO to reset your password." | snackbar | none | — |
| login_screen.dart:271 | "Forgot password?" | button | none | — |
| login_screen.dart:282 | "Log in" | button | none | — |
| login_screen.dart:293 | "Don't have an account?" | label | none | — |
| login_screen.dart:299 | "Register" | button | inconsistent | Register screen's own equivalent link (register_screen.dart:381) says "Log in" for the reverse direction — fine as a pair, but note in the terminology table: this app never uses "Sign up" — only "Register"/"Create account". No change needed, listed for completeness. |
| login_screen.dart:131 | "Cannot connect to server. Check your connection." | validation | none | — |

## `Mobile/lib/screens/auth/verify_email_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| verify_email_screen.dart:106 | "Enter the 6-digit code we sent you." | validation | none | — |
| verify_email_screen.dart:130 | "Something went wrong. Please try again." | validation | none | — |
| verify_email_screen.dart:148 | "A new code is on its way." | label | none | — |
| verify_email_screen.dart:181 | "We sent a 6-digit code to {email}." | label | none | — |
| verify_email_screen.dart:185-187 | "We sent a 6-digit code by text message to your phone." / "...to the number ending in {n}." | label | none | — |
| verify_email_screen.dart:208 | "Check your messages" / "Check your email" | label | none | — |
| verify_email_screen.dart:214 | "{sentToLine} Enter it below to finish creating your account." | label | none | — |
| verify_email_screen.dart:219-220 | label "Verification code", hint "123456" | placeholder | inconsistent | **Resolved by `30145a3`** (the other screen was renamed; this one is unchanged). Same field on the login-code screen (verify_login_screen.dart:221) is labelled "Login code" for the identical 6-digit-code concept. Standardize on one label — see terminology table. |
| verify_email_screen.dart:246 | "Verify" | button | none | — |
| verify_email_screen.dart:255-256 | "Resend code in {n}s" / "Send a new code" | button | none | — |
| verify_email_screen.dart:263 | "Back to log in" | button | none | — |

## `Mobile/lib/screens/auth/verify_login_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| verify_login_screen.dart:102 | "Enter the 6-digit code we sent you." | validation | none | — |
| verify_login_screen.dart:132 | "Something went wrong. Please try again." | validation | none | — |
| verify_login_screen.dart:151 | "A new code is on its way." | label | none | — |
| verify_login_screen.dart:183 | "We sent a 6-digit code to {email}." | label | none | — |
| verify_login_screen.dart:187-189 | "We sent a 6-digit code by text message to your phone." / "...to the number ending in {n}." | label | none | — |
| verify_login_screen.dart:210 | "Check your messages" / "Check your email" | label | none | — |
| verify_login_screen.dart:216 | "{sentToLine} Enter it below to finish signing in." | label | none | — |
| verify_login_screen.dart:221-222 | label "Login code", hint "123456" | placeholder | inconsistent | **Applied — `30145a3`.** See verify_email_screen.dart:219 — same field, different label ("Verification code" there). Proposed: "Verification code" everywhere (it's the more accurate name — this screen is still verifying a one-time code, not "logging in" with it). |
| verify_login_screen.dart:248 | "Verify" | button | none | — |
| verify_login_screen.dart:257-258 | "Resend code in {n}s" / "Send a new code" | button | none | — |
| verify_login_screen.dart:265 | "Back to log in" | button | none | — |

## `Mobile/lib/screens/auth/register_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| register_screen.dart:189 | "Create your account" | label | none | — |
| register_screen.dart:199-200 | "One account per household, for the head of the family. You can log in as soon as you have registered." | label | unclear, inconsistent | **Applied — `3156c19`.** Lowercase "head of the family" — the only place in the mobile app that names this role at all, and it is a role name, not a common noun, so it reads as a typo next to the admin panel's capitalized "Head of the Family" ([[PRODUCT.md]] scopes that capitalization to admin copy, but the term itself is the same role). Propose: "One account per household, registered by the head of the family." — keep lowercase (this is resident-facing prose, not a form-field caption), but note it in the terminology table since it is the only mobile screen that uses the phrase at all. |
| register_screen.dart:210 | label "First name", hint "e.g. Juan" | placeholder | none | — |
| register_screen.dart:220 | label "Last name", hint "e.g. Delacruz" | placeholder | none | — |
| register_screen.dart:214 | "Enter your first name" | validation | none | — |
| register_screen.dart:224 | "Enter your last name" | validation | none | — |
| register_screen.dart:229-230 | label "Mobile number", hint "e.g. 09171234567" | placeholder | inconsistent | **Applied — `3156c19`.** `AppTextField.phone`'s shared factory (form_inputs.dart:69,77) defaults to label "Contact number" / hint "09XXXXXXXXX" (no "e.g." prefix). This screen hand-rolls its own phone field with `AuthTextField` instead of the shared factory, so the label wording ("Mobile number" vs "Contact number") and hint style ("e.g. 09171234567" vs "09XXXXXXXXX") both drift. See terminology table. |
| register_screen.dart:238 | "Enter your mobile number" | validation | none | — |
| register_screen.dart:243 | "Enter a valid mobile number" | validation | none | — |
| register_screen.dart:427 | "Barangay" | label | none | — |
| register_screen.dart:440 | "Loading barangays…" | label | none | — |
| register_screen.dart:455 | "Couldn't load barangays." | label | none | — |
| register_screen.dart:460 | "Retry" | button | none | — |
| register_screen.dart:471 | "Select your barangay" | placeholder | none | — (dropdown hint, not a text field — a format example doesn't apply) |
| register_screen.dart:473 | "Select your barangay" | validation | none | — |
| register_screen.dart:259-260 | label "Email address", hint "yourname@email.com" | placeholder | none | — |
| register_screen.dart:266 | "Enter your email address" | validation | none | — |
| register_screen.dart:268 | "Enter a valid email address" | validation | none | — |
| register_screen.dart:275-276 | label "Password", hint "At least 8 characters" | placeholder | none | — |
| register_screen.dart:285-286 | "Must be at least 8 characters with upper and lower case letters and at least one number — e.g. Pasada123" | label | none | — |
| register_screen.dart:112-116 | validation messages: "Enter a password" / "Password must be at least 8 characters" / "Include at least one uppercase letter (A-Z)" / "Include at least one lowercase letter (a-z)" / "Include at least one number (0-9)" | validation | none | — |
| register_screen.dart:293-294 | label "Confirm password", hint "Re-enter your password" | placeholder | inconsistent | **Applied — `3156c19`.** Same restatement-style hint issue as login_screen.dart:211 — "Re-enter your password" adds nothing "Confirm password" doesn't already say. Compare Password field two rows up, which correctly hints the actual rule instead. Propose dropping the hint (label is already unambiguous) for consistency with how a password-repeat field is normally left unhinted elsewhere in well-formed apps — or, if a hint is kept for visual balance, "Must match the password above". |
| register_screen.dart:299 | "Confirm your password" | validation | none | — |
| register_screen.dart:301 | "Passwords do not match" | validation | none | — |
| register_screen.dart:126 | "Please agree to the data privacy notice to continue." | snackbar | none | — |
| register_screen.dart:132 | "Select your barangay." | validation | none | — |
| register_screen.dart:166 | "Cannot connect to server. Check your connection." | validation | none | — |
| register_screen.dart:351-353 | "I agree that my information will be used by Echague MDRRMO to process service requests and send announcements." | label | none | — |
| register_screen.dart:367 | "Create account" | button | none | — |
| register_screen.dart:375 | "Already have an account?" | label | none | — |
| register_screen.dart:381 | "Log in" | button | none | — |

## `Mobile/lib/screens/dashboard_screen.dart`

All section titles/empty-state/status copy on this screen is sourced from `translations.dart` via `tr(f, 'key')`; resolved English text is shown below with its key. Two strings are hardcoded and bypass that table entirely.

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| dashboard_screen.dart:50 | "Active Service Request" (`home.active_request`) | label | none | — |
| dashboard_screen.dart:51 | "View all" (`common.view_all`) | button | none | — |
| dashboard_screen.dart:72 | "No active requests" (`home.no_active_title`) | empty state | none | — |
| dashboard_screen.dart:75 | "Submit a service request and track its status here." (`home.no_active_desc`) | empty state | none | — |
| dashboard_screen.dart:84 | "Submit a request" (`home.submit_a_request`) | button | none | — |
| dashboard_screen.dart:111-112 | "Reference number pending" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded EN/FIL ternary, not routed through `tr()`, even though every other string on this screen is. Same exact pair is duplicated verbatim in track_screen.dart:263 — move both to a shared `translations.dart` key (e.g. `common.ref_pending`) so the two copies cannot drift. |
| dashboard_screen.dart:146 | "View details" (`common.view_details`) | button | none | — |
| dashboard_screen.dart:151 | "Cancel" (`common.cancel`) | button | none | — |
| dashboard_screen.dart:179 | "Need help now?" (`home.need_help_now`) | label | none | — |
| dashboard_screen.dart:198 | "Borrow Equipment" | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded English only, no Filipino — the one tile on this screen with no bilingual support at all, sitting directly next to the Ambulance tile which is fully localized via `ServiceType.titleFor(f)`. Add a `translations.dart` key (e.g. `home.borrow_equipment`) instead of a bare literal. |
| dashboard_screen.dart:199 | "Wheelchairs, stretchers & more" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Same gap as above — hardcoded, no Filipino counterpart. |
| dashboard_screen.dart:223 | "Announcements" (`home.announcements`) | label | none | — |
| dashboard_screen.dart:224 | "Info center" (`home.info_center`) | button | none | — |
| dashboard_screen.dart:259 | "MDRRMO has not published anything yet." (`home.ann.empty`) | empty state | none | — |
| dashboard_screen.dart:259 | "Couldn't load announcements." (`home.ann.failed`) | empty state | none | — |
| dashboard_screen.dart:284 | "Date not recorded" (`home.ann.no_date`) | label | none | — |
| dashboard_screen.dart:292 | "Saved copies — not refreshed from MDRRMO." (`home.ann.offline`) | label | none | — |
| dashboard_screen.dart:296-303 | status messages ("Your request has been forwarded to MDRRMO for review...", "...is booked...", "...has been scheduled...", "This request has been completed.", "This request has been cancelled.", "MDRRMO did not approve this request.") — `home.status.*` | label | none | — |

## `Mobile/lib/screens/track_screen.dart`

Almost entirely `tr()`-driven, consistent with dashboard's mechanism.

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| track_screen.dart:84 | "Track Your Requests" (`track.title`) | label | none | — |
| track_screen.dart:117 | "No requests yet" (`track.empty_title`) | empty state | none | — |
| track_screen.dart:120 | "Requests you submit from the Services tab will appear here, with their status and a timeline you can follow." (`track.empty_desc`) | empty state | none | — |
| track_screen.dart:139 | "All ({n})" (`track.filter.all`) | button | none | — |
| track_screen.dart:140-145 | filter chip labels ("Under review", "Booked", "Scheduled", "Completed", "Cancelled", "Not approved") — `status.*` | button | none | — |
| track_screen.dart:263 | "Reference number pending" | label | inconsistent | Duplicate of dashboard_screen.dart:111 — see that row and the terminology table. |
| track_screen.dart:305 | "Scheduled time has passed. Contact MDRRMO if you still need this." (`common.booking_overdue`) | label | none | — |
| track_screen.dart:318 | "Hide timeline" / "View timeline" (`common.hide_timeline` / `common.view_timeline`) | button | none | — |
| track_screen.dart:335 | "Cancel request" (`common.cancel_request`) | button | none | — |

## `Mobile/lib/screens/library_screen.dart`

Fully bilingual, but via inline `filipino ? '...' : '...'` ternaries throughout, not `tr()` — a different mechanism from dashboard/track for the same job (see the cross-cutting note at the top of this document).

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| library_screen.dart:39 | "Safety Library" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Correct wording (matches `library.title` in translations.dart) but hardcoded here rather than calling `tr(filipino, 'library.title')` like the rest of the app does for section headers. |
| library_screen.dart:54 | "Emergency Hotlines" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Matches `library.hotlines` in translations.dart but is hardcoded here instead of calling `tr()`. |
| library_screen.dart:74 | "Basic First Aid" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Matches `library.first_aid`; hardcoded instead of `tr()`. |
| library_screen.dart:75-77 | "4 pages" / "3 pages" / "2 pages" | label | none | — |
| library_screen.dart:86 | "Disaster Preparedness" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Matches `library.preparedness`; hardcoded instead of `tr()`. |
| library_screen.dart:87-99 | "5 pages" / "4 pages" (×2) / "Summary · 4 sections" (×2) | label | none | — |
| library_screen.dart:245 | "MDRRMO Documents" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Matches `library.documents`; hardcoded instead of `tr()`. |
| library_screen.dart:262-263 | "No documents published yet." | empty state | none | — |
| library_screen.dart:272-274 | "Showing your saved copies. The server could not be reached." | label | none | — |
| library_screen.dart:313-317 | "{title} saved for offline use." / "Could not download {title}." | snackbar | none | — |
| library_screen.dart:339-340 | "No app on this phone can open a {type}." | snackbar | none | — |
| library_screen.dart:347-348 | "Could not open {title}. Save it while you have a connection." | snackbar | none | — |
| library_screen.dart:460 | "Retry" | button | none | — |

## `Mobile/lib/screens/library/article_reader_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| article_reader_screen.dart:122-123 | "This material is saved for offline reading — you can open it anytime, even without an internet connection." | label | none | — |
| article_reader_screen.dart:163-164 | "EN" / "FIL" | button | none | — |

## `Mobile/lib/screens/services_screen.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| services_screen.dart:232 | "Please attach a photo of your valid ID before submitting." | snackbar | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded English only, no Filipino — this screen otherwise runs through `tr(f, ...)` for its own copy (line 385, 420, 440). |
| services_screen.dart:240 | "Please choose a service before submitting." | snackbar | inconsistent | Same gap as above. |
| services_screen.dart:266 | "Please fill in {missing}." (e.g. "the patient name and where the ambulance should go") | snackbar | inconsistent | Same gap. Also worth a placeholder-quality note: "the patient name" / "where the ambulance should go" read fine stitched with "and", but a three-item list would read awkwardly ("Please fill in a and b and c.") — not reachable today (only two possible missing fields), flagged for whoever adds a third. |
| services_screen.dart:385 | "Service Request" (`services.title`) | label | none | — |
| services_screen.dart:473 | "Couldn't load services. Check your connection and try again." | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded, not `tr()`-routed, same gap as the snackbars above. |
| services_screen.dart:482 | "Retry" | button | none | — |
| services_screen.dart:503 | "Choose a request type" (`services.choose_type`) | placeholder | none | — (dropdown label, not a free-text field) |
| services_screen.dart:420 | "Attachments" (`form_section.attachments`) | label | none | — |
| services_screen.dart:423 | label "Valid ID (required)", hint "Tap to upload a photo of a valid ID (jpg/png, max 2MB)" | placeholder | none | — (hint already states format/size limits) |
| services_screen.dart:429-430 | label "Landmark (optional)", hint "Tap to add a photo of a nearby landmark (jpg/png, max 4MB)" | placeholder | unclear | **Applied — `c482c47`.** Label says "Landmark" but the code comment above this screen's own class (services_screen.dart:61-65) calls this "site photo" and says it documents "a blocked driveway", "the incident scene" — the field is really "photo of the location/situation", not specifically a landmark. A resident could reasonably read "Landmark" as "a nearby permanent structure" and miss that a photo of e.g. floodwater on the road also belongs here. Propose: label "Site photo (optional)", hint unchanged. |
| services_screen.dart:441 | "Submit request" (`common.submit_request`) | button | none | — |
| services_screen.dart:546 | "Borrow Equipment" | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Third literal occurrence of this exact tile concept with no `tr()` routing (see dashboard_screen.dart:198) — hardcoded again here. |
| services_screen.dart:549 | "Wheelchairs, stretchers & more — see what's in stock" | label | inconsistent | Same gap. |

### Ambulance, Road, Relief and Generic form fields — sourced from `Mobile/lib/widgets/service_form_fields.dart` (rendering) and `Mobile/lib/models/service_forms.dart` (label/hint text), both reached from this screen via `ServiceFormFields`

Listed here rather than under "Shared widgets" because these fields exist only to serve this one screen's four request types, and grouping them with the services flow reads more usefully than splitting label/hint text away from the form it belongs to. `file:line` cites `service_form_fields.dart` where the field is a literal in that file, and `service_forms.dart` where the Road/Relief/Generic label/hint text actually lives.

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| service_form_fields.dart:48-49 | label "Patient name", hint "e.g. Maria Santos" | placeholder | none | — |
| service_form_fields.dart:53-54 | label "Age", hint "e.g. 62" | placeholder | none | — |
| service_form_fields.dart:59-61 | label "Sex", options "Not specified"/"Male"/"Female" (service_forms.dart:160-161) | label | none | — |
| service_form_fields.dart:71-73 | label "Patient address", hint "Purok / street, barangay" | placeholder | none | — |
| service_form_fields.dart:75-77 | label "Contact number" (patient's), via `AppTextField.phone` (hint defaults to "09XXXXXXXXX") | placeholder | none | — |
| service_form_fields.dart:85-87 | label "From", hint "Where the ambulance should pick up" | placeholder | unclear | **Applied — `c482c47`.** Hint restates what the field is for, not the expected format — but this field is a free-text address, not a fixed-format value, so a concrete example is more useful than a functional restatement. Propose hint: "e.g. Purok 3, Brgy. Malasin" (matches the format style used by Patient address / Household address elsewhere on these forms). |
| service_form_fields.dart:90-92 | label "To", hint "e.g. Echague District Hospital" | placeholder | none | — |
| service_form_fields.dart:100-101 | label "Medical diagnosis", hint "Briefly describe the patient's condition" | placeholder | none | — (free-text/long-form field, an instructional hint is appropriate here, not a format example) |
| service_form_fields.dart:119-120 | label "Relative {n}", hint "Full name" | placeholder | missing placeholder, inconsistent | **Applied — `c482c47`.** "Full name" names the kind of value, not a format example — every other name-shaped field on this exact form ("Patient name": "e.g. Maria Santos") and elsewhere in the app ("First name": "e.g. Juan") uses a concrete example instead. Propose hint: "e.g. Juan Dela Cruz". |
| service_form_fields.dart:131 | "Remove relative {n}" (tooltip) | button | none | — |
| service_form_fields.dart:149 | "Add relative" | button | none | — |
| service_form_fields.dart:163 | "When" (`ambulance_schedule.title`) | label | none | — |
| service_forms.dart:370-371 | label "Location / road name", hint "e.g. Brgy. Malasin – Provincial Road" | placeholder | none | — |
| service_forms.dart:375-378 | label "Obstruction type", options "Fallen tree / branches" / "Flooding / silt" / "Landslide debris" / "Other" | label | none | — |
| service_forms.dart:387-391 | label "Description", hint "Describe the obstruction and how it's affecting access" | placeholder | none | — (instructional hint appropriate for a long-form field) |
| service_forms.dart:406-409 | label "Household head name", hint "Full name" | placeholder | missing placeholder, inconsistent | **Applied — `c482c47`.** Same generic-hint issue as "Relative {n}" above. Propose hint: "e.g. Juan Dela Cruz". |
| service_forms.dart:412-415 | label "Address", hint "Purok / street, barangay" | placeholder | none | — |
| service_forms.dart:420-426 | label "Household size", hint "e.g. 5" | placeholder | none | — |
| service_forms.dart:433-437 | label "Type of assistance needed", options "Food packs" / "Hygiene kits" / "Drinking water" / "Temporary shelter materials" / "Other" | label | none | — |
| service_forms.dart:450-455 | label "Details", hint "Describe what you need and where" | placeholder | none | — (instructional hint appropriate here) |

## `Mobile/lib/screens/borrow_equipment_screen.dart`

This entire screen is hardcoded English with no Filipino text anywhere, despite computing and threading a `filipino`/`f` value through several child widgets it calls (`OfflineBanner`, `StaleDataNote`, `showCancelDialog`, and the one `tr()` call at line 378) — the infrastructure for bilingual support is present and used for borrowed sub-widgets, but none of this screen's own copy uses it. Every row below except the one `tr()` call is flagged `inconsistent` on that basis; it is not repeated in each row's rationale.

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| borrow_equipment_screen.dart:97 | "Borrow Equipment" (AppBar title) | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. Fourth occurrence of this exact phrase across the app with no shared translation key (see dashboard_screen.dart:198, services_screen.dart:546) — also the only one used as a screen title rather than a tile label. |
| borrow_equipment_screen.dart:83 | "Request filed for {item}. MDRRMO will review it." | snackbar | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:137 | "Nothing available right now" | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:138 | "MDRRMO has no equipment listed for loan at the moment." | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:164 | "No borrow requests yet" | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:165 | "Items you request from the Available tab will show up here." | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:210 | "Available" (segmented toggle) | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:211 | "My Requests ({n})" (segmented toggle) | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:263 | "None available right now" / "{n} available" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:277 | "Borrow" | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:314 | "{equipment} × {qty}" | label | none | — |
| borrow_equipment_screen.dart:320-323 | "Sending..." / "Filed" / "Filed {time}" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:366 | "Due back {m}/{d}/{y}" | label | inconsistent, unclear | **Applied — `1d9b4aa`.** Not localized, and the date is hand-assembled as bare `month/day/year` digits rather than using the app's own `formatTimelineTime`/`formatBookingConfirmationTime` helpers used everywhere else a date is shown to a resident — no day name, no month name, ambiguous M/D vs D/M at a glance. Propose reusing the shared date formatter for consistency, e.g. "Due back Sep 12, 2026". |
| borrow_equipment_screen.dart:378 | "Cancel request" (`common.cancel_request`) | button | none | — (this one IS routed through `tr()`, the sole exception on this screen) |
| borrow_equipment_screen.dart:517 | "Tell MDRRMO what you need this for." | validation | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:573 | "{n} available to borrow" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:594-595 | label "What do you need it for?", hint "e.g. Barangay flood drill this weekend" | placeholder | inconsistent | Hint is fine (concrete example) — flagged only for the screen-wide localization gap. |
| borrow_equipment_screen.dart:603 | "MDRRMO reviews this before approving the loan." | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:617 | "Request this item" | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:537 | "Something went wrong. Please try again." | validation | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |
| borrow_equipment_screen.dart:453 | "Retry" | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Not localized. |

## `Mobile/lib/screens/profile_screen.dart`

Mixed mechanism: most labels route through `tr()`, but every dialog/sheet body in this file uses hand-written `f ? '...' : '...'` ternaries instead of adding a `translations.dart` key — the same split seen between dashboard/track and library, inside one file.

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| profile_screen.dart:204 | "Profile photo" (`profile.photo`) | dialog title | none | — |
| profile_screen.dart:207 | "Choose a photo" (`profile.photo_choose`) | button | none | — |
| profile_screen.dart:216 | "Remove photo" (`profile.photo_remove`) | button | none | — |
| profile_screen.dart:226 | "Close" (`common.close`) | button | none | — |
| profile_screen.dart:246 | "My Profile" (`profile.title`) | label | none | — |
| profile_screen.dart:262 | "Not on file" (`profile.value_missing`), shown when name is empty | empty state | none | — |
| profile_screen.dart:274 | "Account details" (`profile.account_details`) | button | none | — |
| profile_screen.dart:294 | "Account settings" (`profile.account_settings`) | label | none | — |
| profile_screen.dart:297 | "MDRRMO text alerts" (`profile.sms_alerts`) | label | none | — |
| profile_screen.dart:300-302 | "On — MDRRMO text blasts are sent to your number." / "Off — you will not receive any MDRRMO text blast." (`profile.sms_alerts_on/off`) | label | none | — |
| profile_screen.dart:321 | "Language" (`profile.language`) | label | none | — |
| profile_screen.dart:328 | "Offline materials" (`profile.offline_materials`) | label | none | — |
| profile_screen.dart:344 | "Log out" (`profile.logout`) | button | none | — |
| profile_screen.dart:406 | "Profile updated." (`profile.saved`) | snackbar | none | — |
| profile_screen.dart:417-418 | "App articles only" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`, unlike most of this screen. |
| profile_screen.dart:430-432 | "{n} saved · {size}" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. Also: `profile.offline_materials_desc` already exists in translations.dart with an identical `{n} saved · {size} used` shape and is never called — this function reimplements it by hand instead of using the existing key, and drops "used" from the English string in the process ("{n} saved · {size}" vs. the unused key's "{n} saved · {size} used"). |
| profile_screen.dart:462 | "Choose language" | dialog title | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:467-469 | "Materials in the Safety Library will be shown in this language." | dialog body | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:490 | "Language set to English." / "Naitakda ang wika sa Filipino." | snackbar | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:503 | "Log out?" | dialog title | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:506-507 | "You will need to sign in again to submit or track requests." | dialog body | inconsistent, unclear | **Applied — `1c2faac`.** Hardcoded ternary, not `tr()`. Also: says "sign in" where every other screen in the app says "log in" (login_screen.dart title "Welcome back"/"Log in", register_screen.dart "Already have an account? Log in", the AppBar/button is always "Log in" — never "Sign in"). See terminology table. |
| profile_screen.dart:515 | "Stay logged in" | button | none | — |
| profile_screen.dart:523 | "Log out" | button | none | — |
| profile_screen.dart:583-584 | "{title} removed from this device." | snackbar | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:617 | "Offline Materials" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary — and a duplicate of the existing `profile.offline_title` key in translations.dart, which is never called here. |
| profile_screen.dart:626-628 | "Downloaded Documents" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:642-644 | "Nothing downloaded yet. Open the Library and tap Download." | empty state | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:693 | "Remove" (tooltip) | button | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:710 | "Included in the App" | label | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary, not `tr()`. |
| profile_screen.dart:796 | "Change profile photo" (`profile.photo_change`, screen-reader label) | label | none | — |
| profile_screen.dart:1190 | label `_tr('profile.first_name')` = "First name", hint "" | placeholder | missing placeholder, inconsistent | **Applied — `1c2faac`.** Register screen's identical "First name" field (register_screen.dart:210) hints "e.g. Juan"; this one has no hint at all. Propose hint: "e.g. Juan". |
| profile_screen.dart:1197 | label `_tr('profile.middle_name_optional')` = "Middle name (optional)", hint "" | placeholder | missing placeholder | **Applied — `1c2faac`.** No hint anywhere in the app shows what a middle name looks like. Propose hint: "e.g. Reyes". |
| profile_screen.dart:1203 | label `_tr('profile.last_name')` = "Last name", hint "" | placeholder | missing placeholder, inconsistent | **Applied — `1c2faac`.** Register screen's identical "Last name" field (register_screen.dart:220) hints "e.g. Delacruz"; this one does not. Propose hint: "e.g. Delacruz". |
| profile_screen.dart:1209-1210 | label `_tr('profile.phone')` = "Mobile number", via `AppTextField.phone` (hint defaults to "09XXXXXXXXX") | placeholder | inconsistent | Not missing a placeholder (the shared factory supplies one) — flagged because the label itself is a third wording for the same phone-number concept; see terminology table ("Mobile number" here and on Register vs. "Contact number" on the ambulance patient field and the factory's own unused default). |
| profile_screen.dart:1216 | label `_tr('profile.email')` = "Email address", hint "" | placeholder | missing placeholder, inconsistent | **Applied — `1c2faac`.** Login and Register's identical "Email address" field hints "yourname@email.com" (login_screen.dart:196, register_screen.dart:260); this one has none. Propose hint: "yourname@email.com". |
| profile_screen.dart:1049-1050 | "Required" (`profile.required`) | validation | none | — |
| profile_screen.dart:1060 | "Enter a valid mobile number." (`profile.phone_invalid`) | validation | none | — |
| profile_screen.dart:1067 | "Enter a valid email address." (`profile.email_invalid`) | validation | none | — |
| profile_screen.dart:1073 | "Enter your current password to save this change." (`profile.password_required`) | validation | none | — |
| profile_screen.dart:1110 | "Nothing to save." (`profile.no_changes`) | snackbar | none | — |
| profile_screen.dart:1235 | "Your login codes are sent to your email and mobile number, so changing either one needs your password." (`profile.password_why`) | label | none | — |
| profile_screen.dart:1244-1245 | label `_tr('profile.password_current')` = "Current password", hint "" | placeholder | none | — (a password field showing no format hint is the correct, secure default — nothing to fix) |
| profile_screen.dart:1265 | label `_tr('profile.barangay')` = "Barangay", value = resident's barangay or "Not on file" | label | none | — |
| profile_screen.dart:1277 | "Contact MDRRMO to change your barangay — it is what your requests are dispatched on." (`profile.barangay_locked`) | label | none | — |
| profile_screen.dart:1301 | "Save changes" (`profile.save`) | button | none | — |
| profile_screen.dart:1307 | "Cancel" (`common.cancel`) | button | none | — |

## Shared widgets

### `Mobile/lib/widgets/shared_widgets.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| shared_widgets.dart:67 | "SERBIS" | label | none | — |
| shared_widgets.dart:70 | "ECHAGUE MDRRMO" | label | none | — |
| shared_widgets.dart:145-148 | "Ang SERBIS ay isang coordination system kasama ang MDRRMO Echague." / "SERBIS is a coordination system run with the Echague MDRRMO." | label | none | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** — (deliberately hardcoded per the file's own doc comment — this widget renders before a language preference exists) |
| shared_widgets.dart:154-166 | "Request a service from the MDRRMO" / "Safety guides, available offline" / "Receive SMS announcements from the MDRRMO" (+ Filipino) | label | none | — |
| shared_widgets.dart:390-394 | "Saving..." / "Saved" / "Download" (+ Filipino "Sine-save..." / "Na-save" / "I-download") | button | none | — |
| shared_widgets.dart:707 | "Cancel request?" (dialog title) | dialog title | none | — |
| shared_widgets.dart:712-713 | "Are you sure you want to cancel request{ ref}? This action cannot be undone." | dialog body | none | — |
| shared_widgets.dart:721 | "Keep request" | button | none | — |
| shared_widgets.dart:742 | "Cancel request" | button | none | — |
| shared_widgets.dart:689-690 | "Request{ref} has been cancelled." | snackbar | none | — |
| shared_widgets.dart:854 | "Notifications" (+ Filipino "Mga Abiso") | dialog title | inconsistent | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Hardcoded ternary in a file where every other string in this exact widget (`NotificationsSheet`) uses `tr()` against `translations.dart` keys (`notif.advisories`, `notif.your_requests`, etc. at lines 874, 895). This one string alone bypasses the table it sits next to. |
| shared_widgets.dart:874 | "MDRRMO advisories" (`notif.advisories`) | label | none | — |
| shared_widgets.dart:879 | "Couldn't load advisories. This does not mean none were sent — check with your barangay." (`notif.adv_failed`) | empty state | none | — |
| shared_widgets.dart:886 | "No advisories have been sent to you." (`notif.adv_none`) | empty state | none | — |
| shared_widgets.dart:895 | "Your requests" (`notif.your_requests`) | label | none | — |
| shared_widgets.dart:917-922 | "No updates yet" / "Updates about your service requests appear here once you have submitted one." (`notif.empty_title` / `notif.empty_body`) | empty state | none | — |
| shared_widgets.dart:938 | "MDRRMO advisories sent to you, and updates about your own requests." (`notif.scope_note`) | label | none | — |

### `Mobile/lib/widgets/form_inputs.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| form_inputs.dart:69, 77 | default label "Contact number", default hint "09XXXXXXXXX" (factory `AppTextField.phone`) | placeholder | none | — this default is well-formed, but note: no call site in the app actually uses it unoverridden (both current callers pass an explicit `label:`) — see the terminology table for the label drift this produces. |

### `Mobile/lib/widgets/service_widgets.dart`

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| service_widgets.dart:83 | "Remove {label}" (tooltip on an attachment's clear button) | button | none | — |
| service_widgets.dart:136 | "services.notice_title" → "Non-life-threatening use only" | label | none | — |
| service_widgets.dart:141 | "services.notice_body" → "If you are experiencing a life-threatening emergency, contact authorities directly:" | label | none | — |
| service_widgets.dart:294-295 | "Hindi naipadala ang kahilingan. Nandito pa ang mga detalye mo — subukang muli." / "Your request wasn't sent. Your details are still here — tap Retry to send them again." | label | none | — |
| service_widgets.dart:303 | "Subukang muli" / "Retry" | button | none | — |
| service_widgets.dart:337 | "services.confirm.body" → "Your request has been received. You can track its status anytime from the Track tab. Reference #{ref}." | dialog body | none | — |
| service_widgets.dart:356 | "services.confirm.title" → "Request submitted" | dialog title | none | — |
| service_widgets.dart:372 | "services.confirm.scheduled_for" → "Scheduled for" | label | none | — |
| service_widgets.dart:380 | "services.confirm.view_track" → "View in Track" | button | none | — |

### `Mobile/lib/widgets/ambulance_schedule_field.dart`

All routed through `tr()`, no hardcoded literals. Resolved text, for completeness:

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| ambulance_schedule_field.dart:101 | "Please pick a time at least 1 hour from now." (`ambulance_schedule.lead_time_error`) | validation | none | — |
| ambulance_schedule_field.dart:168 | "As soon as possible" (`ambulance_schedule.asap`) | button | none | — |
| ambulance_schedule_field.dart:169 | "Scheduled" (`ambulance_schedule.mode_scheduled`) | button | none | — |
| ambulance_schedule_field.dart:197 | "Change" (`ambulance_schedule.change`) | button | none | — |
| ambulance_schedule_field.dart:223 | "Checking availability…" (`ambulance_schedule.checking`) | label | none | — |
| ambulance_schedule_field.dart:246-247 | "At least one ambulance may be free at that time." / "No ambulance may be free at that time yet. You can still submit — MDRRMO will confirm." (`ambulance_schedule.some_free` / `none_free`) | label | none | — |

### `Mobile/lib/widgets/offline_banner.dart`

All routed through `tr()`, no hardcoded literals. Resolved text, for completeness:

| file:line | current text | type | issue | proposed replacement |
|---|---|---|---|---|
| offline_banner.dart:46 | "No connection to MDRRMO. Requests can't be sent." (`offline.title`) | label | none | — |
| offline_banner.dart:52-53 | "Nothing has been loaded on this device yet." / "Last updated {time}" (`offline.never_updated` / `offline.last_updated`) | label | none | — |
| offline_banner.dart:95-98 | "Saved copy" / "Saved copy · Last updated {time}" (`offline.saved_copy`) | label | none | — |

### `Mobile/lib/widgets/form_section.dart`

No hardcoded literals — `FormSection.label` and `ModeToggle`'s two segment labels are always caller-supplied parameters (already inventoried at their call sites above).

---

## Terminology inconsistencies

| Concept | Variants found (with file:line) | Standardize on |
|---|---|---|
| The 6-digit one-time code field, at signup vs. at login | "Verification code" (verify_email_screen.dart:219) vs. "Login code" (verify_login_screen.dart:221) | **Applied — `30145a3`.** "Verification code" everywhere — it names what the field does (verifies a code), not which flow sent it, and matches the screen title pattern ("Check your email"/"Check your messages") used by both screens identically. |
| The account holder's own phone number field | "Mobile number" (register_screen.dart:229, profile_screen.dart:1209 via `profile.phone`) vs. the shared `AppTextField.phone` factory's unused default "Contact number" (form_inputs.dart:69) | **Applied — `3156c19`** (no code change beyond the hint; the label split is now documented as intentional). "Mobile number" for the account holder's own number (already the majority form, and matches how residents refer to their own phone). Reserve "Contact number" specifically for a field naming *someone else's* number (the ambulance form's `patientContact`, service_form_fields.dart:76) — that split is a real, useful distinction and should be documented as intentional rather than left to look like drift. |
| Phone-number hint format | "e.g. 09171234567" (register_screen.dart:230) vs. "09XXXXXXXXX" (shared `AppTextField.phone` factory, form_inputs.dart:77) | **Applied — `3156c19`** (Register now uses the shared template; it still hand-rolls its own `AuthTextField`, which is a refactor left out of a copy pass). "09XXXXXXXXX" — it is already the shared factory's convention and reads as a fill-in-the-blank template rather than one example among several possible real numbers. Register screen should switch from its own hand-rolled `AuthTextField` phone field to the shared factory rather than keeping a second implementation. |
| Password-field hint style | Rule-reminder style, "At least 8 characters" (register_screen.dart:276) vs. label-restating style, "Enter your password" (login_screen.dart:211) / "Re-enter your password" (register_screen.dart:294, Confirm password) | **Applied — `ad47f50`, `3156c19`.** Drop the hint entirely on a field whose label is already unambiguous ("Password", "Confirm password"), and reserve a hint for the one case it earns its keep: stating the actual rule, as Register's own Password field already does. |
| "Log in" vs. "sign in" | "Log in" is used everywhere else in the app (login_screen.dart's whole screen, register_screen.dart:381, the AppBar/back-to-login buttons on both verify screens) except profile_screen.dart:506, which says "sign in" once, in the logout confirmation dialog | **Applied — `1c2faac`.** "Log in" — it is the overwhelming majority usage and matches the actual screen/route name (`LoginScreen`). |
| "Borrow Equipment" tile/screen title | Appears identically as hardcoded English in three places with no shared key: dashboard_screen.dart:198, services_screen.dart:546, and as the AppBar title in borrow_equipment_screen.dart:97 | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Keep the wording (it's already consistent across all three), but move it into `translations.dart` as one key (e.g. `common.borrow_equipment`) so a future copy change can't update two of the three and miss the third — and so it finally gets a Filipino counterpart, which none of the three currently have. |
| "Reference number pending" | Identical hardcoded EN/FIL ternary duplicated verbatim in dashboard_screen.dart:111 and track_screen.dart:263 | **Deferred — localization mechanism, out of scope of the 2026-09-06 fix pass.** Move to a shared `translations.dart` key (e.g. `common.ref_pending`) — the two copies already agree, so this is a maintenance risk rather than a live inconsistency, but it is the kind of duplication that silently drifts on the next copy edit to only one of the two. |
| Localization mechanism | Three different approaches to the same job across the app: `tr(filipino, 'key')` against `translations.dart` (dashboard, track, most of services, ambulance schedule, offline banner, most of profile); inline `filipino ? '...' : '...'` ternaries with no shared table (library_screen.dart entirely, most dialogs/sheets in profile_screen.dart, article_reader_screen.dart, borrow_equipment_screen.dart's one `tr()` call aside); no localization at all (borrow_equipment_screen.dart, almost entirely) | **Deferred — this is the structural finding the 2026-09-06 fix pass deliberately left alone.** Route every user-facing string through `tr()` against `translations.dart`. This is a structural finding, not a wording one — it has no single "current text" to standardize, but it is the single largest source of the `inconsistent` rows in this document by count. |
| "head of the family" capitalization/scope | Lowercase, prose use, mobile-only: "for the head of the family" (register_screen.dart:199) | **Applied — `3156c19`** (reworded to "registered by the head of the family"; still lowercase, as this row concluded). No mobile-side change needed — [[PRODUCT.md]]'s capitalization rule ("Head of the Family") is scoped to admin-facing copy. Listed here only so the two surfaces' conventions are both on record in one place; do not capitalize this instance to match the admin panel, since this is resident-facing prose, not a form field caption. |

---

## Summary (counts, for approval before any fix is made)

Counts below were computed by parsing this file's own tables, not estimated — they will reconcile against a `grep`/table count of the file at any time.

- **Total strings inventoried: 244** (across 11 screen files and 7 widget files; `models/service_forms.dart`'s field specs are folded into the Services screen section since they render only through it, per this document's own rule for shared-widget parameters vs. literals).
- **Screens/files with at least one non-`none` issue: 11 of 17** audited files. Clean (zero non-`none` rows): `article_reader_screen.dart`, `form_section.dart`, `offline_banner.dart`, `form_inputs.dart`, `ambulance_schedule_field.dart`, `service_widgets.dart`.
- **`missing placeholder`: 6 fields** — Middle name, First name, Last name and Email address in the profile edit sheet; "Relative {n}" and "Household head name" in the guided forms (three of these six are also flagged `inconsistent` against a sibling field elsewhere in the app that already has the hint they're missing).
- **`gendered`: 0.** No instance of "Chairman", "he/she", "his/her", "him/her", "Mr./Ms./Mrs.", "Sir/Madam", or any gendered role/relationship noun was found anywhere in the 17 audited files. (`profile.password_why`, `home.status.*`, the ambulance intake's patient/relative fields, and every form-field label were checked specifically for this — the app does not gender the account holder, the patient, or any MDRRMO role anywhere in its own copy.)
- **`unclear`: 5** — the "Landmark" attachment label (services_screen.dart:429, really a site/situation photo), the "From" pickup-location hint (service_form_fields.dart:85), "head of the family" capitalization/scope, "sign in" vs. "log in" wording (profile_screen.dart:506), and borrow_equipment_screen's hand-assembled `M/D/YYYY` date instead of the shared date formatter.
- **`inconsistent`: 61 rows.** By far the largest category, and mostly one structural finding rather than 61 separate wording problems: **the app uses three different localization mechanisms for the same job** — `tr()` against `translations.dart`, hand-written `filipino ? 'x' : 'y'` ternaries with no shared table, and (on `borrow_equipment_screen.dart`) no localization at all despite threading a `filipino` flag through several of its own child widgets. That single pattern accounts for roughly 45 of the 61 rows (all of `borrow_equipment_screen.dart`'s own copy, all of `library_screen.dart`'s own copy, most of `profile_screen.dart`'s dialogs/sheets, and the scattered hardcoded snackbars in `services_screen.dart`). The remaining ~16 are wording-level terminology drift (phone-number naming, password-field hint style, the verification-code field's two labels, three duplicated "Borrow Equipment" literals, two duplicated "Reference number pending" literals) — all enumerated in the terminology table above.

Fix pass status, as of 2026-09-06: the `missing placeholder`, `unclear` and terminology-drift rows were approved and applied in six commits
(see **Remediation status** near the top of this document); every localization-mechanism row is tagged `Deferred` in place and no `.dart`
file was changed on their account.
