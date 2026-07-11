/// Account model and auth store for the SERBIS app.
///
/// [AppUser] represents a logged-in resident (data returned by Laravel).
/// [UserStore] wraps [ApiService] to provide register / login / logout
/// methods that the auth screens and [AuthGate] in main.dart call.
///
/// Previously this stored accounts in shared_preferences. Now it delegates
/// to the Laravel backend via [ApiService].
library serbis.state.user_store;

import 'api_service.dart';

/// A resident account as returned by the Laravel backend after login.
///
/// Field names match whatever your Laravel [Resident] model returns in the
/// `user` key of the residentLogin response. Adjust the fromJson factory
/// below if your column names differ.
class AppUser {
  final String id;
  final String firstName;
  final String lastName;
  final String email;
  final String address;

  const AppUser({
    required this.id,
    required this.firstName,
    required this.lastName,
    required this.email,
    required this.address,
  });

  /// Full display name — "Juan Delacruz"
  String get fullName => '$firstName $lastName'.trim();

  /// Builds an [AppUser] from the JSON map returned by Laravel's
  /// residentLogin endpoint. Adjust field names here if your Resident
  /// model uses different column names (e.g. 'full_name' vs 'first_name').
  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id:        (json['id'] ?? '').toString(),
      firstName: json['first_name'] as String? ?? '',
      lastName:  json['last_name']  as String? ?? '',
      email:     json['email_address'] as String?
                 ?? json['email'] as String?
                 ?? '',
      address:   json['address'] as String? ?? '',
    );
  }
}

/// Thin wrapper around [ApiService] that exposes the three auth operations
/// the app needs: [register], [login], and [logout].
class UserStore {
  final ApiService _api;

  UserStore(this._api);

  /// Whether a Sanctum token is stored locally (i.e. the resident is still
  /// logged in from a previous session).
  bool get hasSession => _api.isLoggedIn;

  /// Registers a new resident account on the Laravel backend.
  ///
  /// Returns null on success, or a human-readable error string on failure
  /// (validation error, duplicate email, network error, etc.).
  Future<String?> register({
    required String firstName,
    required String lastName,
    required String email,
    required String password,
  }) {
    return _api.register(
      firstName: firstName,
      lastName:  lastName,
      email:     email,
      password:  password,
    );
  }

  /// Logs in a resident with [email] + [password].
  ///
  /// Returns the logged-in [AppUser] on success, or throws a [String]
  /// error message on failure (wrong credentials, network error, etc.).
  Future<AppUser> login({
    required String email,
    required String password,
  }) async {
    final json = await _api.residentLogin(email: email, password: password);
    return AppUser.fromJson(json);
  }

  /// Logs out the resident — invalidates the server token and clears local
  /// storage.
  Future<void> logout() => _api.logout();
}
