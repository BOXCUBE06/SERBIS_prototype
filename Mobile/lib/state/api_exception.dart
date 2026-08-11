library serbis.state.api_exception;

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

  const ApiException(this.message, {this.statusCode, this.code});

  /// The account exists and the password was right, but the address was never
  /// verified. The register flow left it half-finished; the resident resumes at
  /// the code screen rather than being told their password is wrong.
  bool get isEmailUnverified => code == 'email_unverified';

  /// The token was rejected. Distinct from a failed login, which the auth
  /// endpoints report as a plain message instead.
  bool get isUnauthorized => statusCode == 401;

  /// No response at all — offline, wrong base URL, or a timeout.
  bool get isNetwork => statusCode == null;

  @override
  String toString() => message;
}
