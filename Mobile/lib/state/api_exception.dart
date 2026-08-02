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

  const ApiException(this.message, {this.statusCode});

  /// The token was rejected. Distinct from a failed login, which the auth
  /// endpoints report as a plain message instead.
  bool get isUnauthorized => statusCode == 401;

  /// No response at all — offline, wrong base URL, or a timeout.
  bool get isNetwork => statusCode == null;

  @override
  String toString() => message;
}
