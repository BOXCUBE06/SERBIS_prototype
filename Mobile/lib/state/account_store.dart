
library serbis.state.user_store;

import 'api_service.dart';

class AppUser {
  final String id;
  final String firstName;

  /// Optional on `tbl_residents`, and optional here. An empty string means the
  /// resident has none on file, which is different from not having loaded it.
  final String middleName;
  final String lastName;
  final String email;

  /// The number the MDRRMO calls back on. Editable by the resident through
  /// `PATCH /me`; it is not the number SMS blasts are keyed on being correct,
  /// so a typo here costs the resident a callback, not an alert.
  final String phone;
  final String address;

  /// Whether a profile photo has been uploaded. The path is never sent to a
  /// client — the server answers this flag and serves the image from
  /// `GET /residents/{id}/photo`.
  final bool hasPhoto;

  /// Whether this resident wants the MDRRMO's text blasts. Editable through
  /// `PATCH /me`; `SmsController` filters its recipient query on the column, so
  /// false here means no blast reaches this number at all.
  final bool smsOptIn;

  const AppUser({
    required this.id,
    required this.firstName,
    this.middleName = '',
    required this.lastName,
    required this.email,
    this.phone = '',
    required this.address,
    this.hasPhoto = false,
    this.smsOptIn = true,
  });

  String get fullName => '$firstName $lastName'.trim();

  /// Two letters for the avatar when there is no photo. Empty when the profile
  /// carries no name at all, so nothing invented appears in the circle.
  String get initials {
    final first = firstName.trim();
    final last = lastName.trim();
    return '${first.isEmpty ? '' : first[0]}${last.isEmpty ? '' : last[0]}'
        .toUpperCase();
  }

  AppUser copyWith({
    String? firstName,
    String? middleName,
    String? lastName,
    String? email,
    String? phone,
    bool? hasPhoto,
    bool? smsOptIn,
  }) {
    return AppUser(
      id: id,
      firstName: firstName ?? this.firstName,
      middleName: middleName ?? this.middleName,
      lastName: lastName ?? this.lastName,
      email: email ?? this.email,
      phone: phone ?? this.phone,
      address: address,
      hasPhoto: hasPhoto ?? this.hasPhoto,
      smsOptIn: smsOptIn ?? this.smsOptIn,
    );
  }

  factory AppUser.fromJson(Map<String, dynamic> json) {
    // tbl_residents has no address column; location is the barangay relation.
    final barangay = json['barangay'];
    final barangayName =
        barangay is Map<String, dynamic> ? barangay['barangay_name'] as String? : null;

    return AppUser(
      id: (json['resident_id'] ?? json['id'] ?? '').toString(),
      firstName: json['first_name'] as String? ?? '',
      middleName: json['middle_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      email: json['email_address'] as String? ?? '',
      phone: json['phone_number'] as String? ?? '',
      address: barangayName ?? '',
      hasPhoto: json['has_photo'] == true,
      // Absent falls back to true, matching the column's own default. Reading a
      // missing key as false would show a resident an "off" switch and tell
      // them they are receiving nothing while the server still sends to them.
      smsOptIn: json['sms_opt_in'] as bool? ?? true,
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

  Future<RegisterOutcome> register({
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

  /// Finishes registration with the emailed code. Returns the signed-in
  /// resident: the server issues a token here, so there is no second trip
  /// through the login screen.
  Future<AppUser> verifyEmail({
    required String email,
    required String code,
  }) async {
    final json = await _api.verifyEmail(email: email, code: code);
    return AppUser.fromJson(json);
  }

  Future<VerificationDelivery?> resendVerificationCode({
    required String email,
  }) {
    return _api.resendVerificationCode(email: email);
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

  /// Saves the resident's own contact details and returns the refreshed
  /// profile, so the caller does not have to re-fetch `/me` to see the result.
  Future<AppUser> updateProfile({
    String? firstName,
    String? middleName,
    String? lastName,
    String? phoneNumber,
    String? email,
    bool? smsOptIn,
  }) async {
    final json = await _api.updateProfile(
      firstName: firstName,
      middleName: middleName,
      lastName: lastName,
      phoneNumber: phoneNumber,
      email: email,
      smsOptIn: smsOptIn,
    );
    return AppUser.fromJson(json);
  }

  /// Uploads a new profile photo and returns the refreshed profile.
  Future<AppUser> setProfilePhoto({
    required List<int> bytes,
    required String fileName,
  }) async {
    final json = await _api.uploadProfilePhoto(bytes: bytes, fileName: fileName);
    return AppUser.fromJson(json);
  }

  Future<AppUser> removeProfilePhoto() async {
    return AppUser.fromJson(await _api.deleteProfilePhoto());
  }

  /// Image bytes for the avatar, or null when there is nothing stored.
  Future<List<int>?> profilePhoto(String residentId) =>
      _api.fetchProfilePhoto(residentId);

  Future<void> logout() => _api.logout();
}
