
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
    // tbl_residents has no address column; location is the barangay relation.
    final barangay = json['barangay'];
    final barangayName =
        barangay is Map<String, dynamic> ? barangay['barangay_name'] as String? : null;

    return AppUser(
      id: (json['resident_id'] ?? json['id'] ?? '').toString(),
      firstName: json['first_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      email: json['email_address'] as String? ?? json['email'] as String? ?? '',
      address: json['address'] as String? ?? barangayName ?? '',
    );
  }
}

/// One row of `tbl_barangay`. The register screen needs the id, not the name —
/// `barangay_id` is a required non-null FK on a resident.
class BarangayOption {
  final int id;
  final String name;

  const BarangayOption({required this.id, required this.name});

  factory BarangayOption.fromJson(Map<String, dynamic> json) {
    final idValue = json['barangay_id'] ?? json['id'];
    return BarangayOption(
      id: idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '') ?? 0,
      name: (json['barangay_name'] ?? json['name'] ?? '') as String,
    );
  }
}

class UserStore {
  final ApiService _api;

  UserStore(this._api);

  bool get hasSession => _api.isLoggedIn;

  Future<String?> register({
    required String firstName,
    String? middleName,
    required String lastName,
    required int barangayId,
    required String phoneNumber,
    required String email,
    required String password,
  }) {
    return _api.register(
      firstName: firstName,
      middleName: middleName,
      lastName: lastName,
      barangayId: barangayId,
      phoneNumber: phoneNumber,
      email: email,
      password: password,
    );
  }

  Future<List<BarangayOption>> barangays() async {
    final rows = await _api.getBarangays();
    return rows.map(BarangayOption.fromJson).toList();
  }

  /// Rebuilds the profile from a stored token on relaunch.
  Future<AppUser> currentUser() async {
    return AppUser.fromJson(await _api.me());
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
