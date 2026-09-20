library serbis.state.api_exception;

import 'verification_delivery.dart';

/// A failed API call. Carries the HTTP status so callers can tell a dead
/// network (`statusCode == null`) from a real rejection, and a message that is
/// already safe to show a resident.
///
/// In its own file so `app_log.dart` can recognise it without importing
/// `api_service.dart`, which imports the log in turn. `api_service.dart`
/// re-exports it, so `import 'api_service.dart'` still sees this type.
class ApiException implements Exception {
  final String message;
  final int? statusCode;

  /// The server's machine-readable reason, when it sent one — `email_unverified`,
  /// `invalid_code`, `resend_too_soon`. Screens route on this instead of matching
  /// the message text, which is written for a person and changes freely.
  final String? code;

  /// Set when the rejection still put a code in flight — the 403 that turns a
  /// login into a verification screen sends one, and reports here where it
  /// went. Null on every other failure.
  final VerificationDelivery? delivery;

  /// The login-MFA challenge id, present on `mfa_required`. Opaque to the
  /// client — it goes back to `/…/login/verify` (or `/resident/login/resend`)
  /// exactly as received, never parsed or stored anywhere else.
  final String? challengeId;

  /// Laravel's `errors` map, flattened to one message per field, so a screen
  /// can put a rejection under the input that caused it instead of in a
  /// form-wide banner. [message] is already the first of these — this is the
  /// same information keyed by field name.
  ///
  /// Empty on every failure that is not a 422, and on a 422 whose body could
  /// not be parsed.
  final Map<String, String> fieldErrors;

  const ApiException(
    this.message, {
    this.statusCode,
    this.code,
    this.delivery,
    this.challengeId,
    this.fieldErrors = const {},
  });

  /// The account exists and the password was right, but the phone number was
  /// never verified. The register flow left it half-finished; the resident
  /// resumes at the code screen rather than being told their password is wrong.
  bool get isPhoneUnverified => code == 'phone_unverified';

  /// The server could not text the code (out of credits, the provider refused,
  /// or it was rate limited). There is no other channel, so the screen says so
  /// and offers to try again; nothing the resident typed is wrong.
  bool get isSmsUnavailable => code == 'sms_unavailable';

  /// This build predates phone login and the server has said so (410). The
  /// message already carries the request to update in both languages.
  bool get isAppUpdateRequired => code == 'app_update_required';

  /// Password proven; a TOTP (admin) or SMS/email (resident) code is what's
  /// left. The login screen routes to a verify screen instead of showing this
  /// as a form error.
  bool get isMfaRequired => code == 'mfa_required';

  /// The account was closed by MDRRMO. The password was correct — this is a
  /// refusal of the account, not of the credentials, so a screen must not
  /// present it as a retryable form error. There is no self-serve way back:
  /// reactivation happens at the office.
  bool get isAccountDeactivated => code == 'account_deactivated';

  /// The token was rejected. Distinct from a failed login, which the auth
  /// endpoints report as a plain message instead.
  bool get isUnauthorized => statusCode == 401;

  /// No response at all — offline, wrong base URL, or a timeout.
  bool get isNetwork => statusCode == null;

  /// Seconds the server says to wait before asking for another code. Present
  /// on `resend_too_soon` and on the unverified-login refusal.
  int? get retryAfter => delivery?.retryAfter;

  @override
  String toString() => message;
}
