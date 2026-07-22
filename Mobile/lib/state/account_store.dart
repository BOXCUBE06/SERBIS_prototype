
library serbis.state.user_store;

import 'api_service.dart';

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

  String get fullName => '$firstName $lastName'.trim();

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: (json['id'] ?? '').toString(),
      firstName: json['first_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      email: json['email_address'] as String? ?? json['email'] as String? ?? '',
      address: json['address'] as String? ?? '',
    );
  }
}

class UserStore {
  final ApiService _api;

  UserStore(this._api);

  bool get hasSession => _api.isLoggedIn;

  Future<String?> register({
    required String firstName,
    required String lastName,
    required String email,
    required String password,
  }) {
    return _api.register(
      firstName: firstName,
      lastName: lastName,
      email: email,
      password: password,
    );
  }

  Future<AppUser> login({
    required String email,
    required String password,
  }) async {
    final json = await _api.residentLogin(email: email, password: password);
    return AppUser.fromJson(json);
  }

  Future<void> logout() => _api.logout();
}
