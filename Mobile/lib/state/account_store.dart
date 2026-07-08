import 'api_service.dart';

/// A SERBIS account, as entered on the registration form and sent to the
/// Laravel backend. The backend is now the source of truth — this class is
/// just a convenient container for passing form data to [UserStore].
class AppUser {
  final String name;
  final String phone;
  final String address;
  final String password;

  const AppUser({
    required this.name,
    required this.phone,
    required this.address,
    required this.password,
  });

  AppUser copyWith({String? name, String? phone, String? address, String? password}) {
    return AppUser(
      name: name ?? this.name,
      phone: phone ?? this.phone,
      address: address ?? this.address,
      password: password ?? this.password,
    );
  }

  Map<String, dynamic> toJson() => {
        'name': name,
        'phone': phone,
        'address': address,
        'password': password,
      };

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        name: json['name'] as String? ?? '',
        phone: json['phone'] as String? ?? '',
        address: json['address'] as String? ?? '',
        password: json['password'] as String? ?? '',
      );
}

/// Thin wrapper around [ApiService] for auth-related calls.
///
/// There is no more local account storage — registration and login both go
/// through the Laravel API. [ApiService] itself handles saving/loading the
/// Sanctum token, so `UserStore` only needs to shape the requests/responses.
class UserStore {
  final ApiService _api;

  UserStore(this._api);

  /// Call once on app startup (e.g. in `AuthGate.initState`) so a
  /// previously-saved token is restored before checking [isLoggedIn].
  Future<void> load() => _api.loadToken();

  /// Whether a valid auth token is currently stored.
  bool get isLoggedIn => _api.isLoggedIn;

  /// Registers a new account with the backend.
  ///
  /// Returns `null` on success, or an error message string if the server
  /// rejected the request (e.g. phone number already taken, validation
  /// error). NOTE: this changed from returning `bool` to returning
  /// `String?` — update any call sites that checked `if (registered)`.
  Future<String?> register(AppUser user) async {
    return _api.register(
      name: user.name,
      phone: user.phone,
      address: user.address,
      password: user.password,
    );
  }

  /// Logs in with phone + password.
  ///
  /// Returns the logged-in user's data from the server on success, or
  /// `null` if the credentials were rejected or the request failed.
  Future<Map<String, dynamic>?> login(String phone, String password) async {
    try {
      return await _api.login(phone: phone, password: password);
    } catch (e) {
      return null;
    }
  }

  /// Logs out the current user and clears the stored token.
  Future<void> logout() => _api.logout();

  /// Fetches the currently logged-in user's data from the server.
  Future<Map<String, dynamic>> getUser() => _api.getUser();

  Object? authenticate(String text, String text2) {}
}