
library serbis.state.user_store;

import '../models/phone_number.dart';
import 'api_service.dart';
import 'user_cache.dart';

class AppUser {
  final String id;
  final String firstName;

  /// Optional on `tbl_residents`, and optional here. An empty string means the
  /// resident has none on file, which is different from not having loaded it.
  final String middleName;
  final String lastName;
  /// Kept because the server still returns it for accounts that gave one. New
  /// accounts have none (a phone number is the login), so this is usually empty
  /// and nothing in the app asks for it.
  final String email;

  /// The number the resident signs in with, as the server stores it
  /// (`+639171234567`). It moves only through the two-step code flow
  /// ([UserStore.requestPhoneChange]); read it through [phoneDisplay] for
  /// anything a person sees.
  final String phone;
  final String address;

  /// `tbl_residents.barangay_id`, null when the payload has none. Editable by a
  /// Head of the Family through `PATCH /me`.
  final int? barangayId;

  /// Purok/street — `tbl_residents.street_address`, added because the
  /// barangay relation alone is not enough for a dispatcher to find a
  /// household (MDRRMO feedback, 2026-09-19). Optional, and editable through
  /// `PATCH /me` the same as every other contact field. An empty string means
  /// the resident has none on file.
  final String streetAddress;

  /// Whether a profile photo has been uploaded. The path is never sent to a
  /// client — the server answers this flag and serves the image from
  /// `GET /residents/{id}/photo`.
  final bool hasPhoto;

  /// Whether this resident wants the MDRRMO's text blasts. Editable through
  /// `PATCH /me`; `SmsController` filters its recipient query on the column, so
  /// false here means no blast reaches this number at all.
  final bool smsOptIn;

  /// `head_of_family` (an individual, the default), `organization` or
  /// `barangay`. A server that predates the column sends none, which reads as
  /// an individual.
  final String accountType;

  /// The organization's name; empty for every other type.
  final String organizationName;

  /// `tbl_residents.status`: Active, Inactive (pending) or Deactivated. Only
  /// consulted for [isAwaitingApproval]. Empty when the payload has none.
  final String status;

  const AppUser({
    required this.id,
    required this.firstName,
    this.middleName = '',
    required this.lastName,
    this.email = '',
    this.phone = '',
    required this.address,
    this.barangayId,
    this.streetAddress = '',
    this.hasPhoto = false,
    this.smsOptIn = true,
    this.accountType = 'head_of_family',
    this.organizationName = '',
    this.status = '',
  });

  /// The number the way a person writes it, `09171234567`.
  String get phoneDisplay => PhoneNumber.display(phone);

  bool get isOrganization => accountType == 'organization';
  bool get isBarangay => accountType == 'barangay';

  /// An organization that registered itself and has not been activated. It
  /// cannot file anything (the server answers 403 account_pending), so the app
  /// shows an approval screen instead of the service list. An individual in the
  /// same status can file, so this is specific to organizations.
  bool get isAwaitingApproval => isOrganization && status.toLowerCase() == 'inactive';

  /// Who this account is, for the top of the home screen: the organization's
  /// name, "Barangay <name>" for a barangay hall, or the person's own name.
  String get accountName {
    if (isOrganization && organizationName.isNotEmpty) return organizationName;
    if (isBarangay) return 'Barangay $address'.trim();
    return fullName;
  }

  /// A `translations.dart` key for the account type's label.
  String get accountTypeKey => isOrganization
      ? 'account.organization'
      : isBarangay
          ? 'account.barangay'
          : 'account.individual';

  String get fullName => '$firstName $lastName'.trim();

  /// The purok/street and barangay together, for the "Same as my address"
  /// checkboxes on the request forms (MDRRMO feedback, 2026-09-19) — the
  /// fuller answer [address] alone cannot give, now that [streetAddress]
  /// exists to ask for it. Falls back to just the barangay when the resident
  /// has not set a street address yet, and to empty when there is no
  /// barangay either (profile not yet loaded).
  String get fullAddress {
    if (streetAddress.isEmpty) return address;
    if (address.isEmpty) return streetAddress;
    return '$streetAddress, $address';
  }

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
    String? streetAddress,
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
      barangayId: barangayId,
      streetAddress: streetAddress ?? this.streetAddress,
      hasPhoto: hasPhoto ?? this.hasPhoto,
      smsOptIn: smsOptIn ?? this.smsOptIn,
      accountType: accountType,
      organizationName: organizationName,
      status: status,
    );
  }

  factory AppUser.fromJson(Map<String, dynamic> json) {
    // The barangay relation, not a street — tbl_residents' own street_address
    // column is read separately below.
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
      barangayId: (json['barangay_id'] as num?)?.toInt(),
      streetAddress: json['street_address'] as String? ?? '',
      hasPhoto: json['has_photo'] == true,
      // Absent falls back to true, matching the column's own default. Reading a
      // missing key as false would show a resident an "off" switch and tell
      // them they are receiving nothing while the server still sends to them.
      smsOptIn: json['sms_opt_in'] as bool? ?? true,
      accountType: json['account_type'] as String? ?? 'head_of_family',
      organizationName: json['organization_name'] as String? ?? '',
      status: json['status'] as String? ?? '',
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
  final UserCache _userCache;

  UserStore(this._api, {UserCache? userCache}) : _userCache = userCache ?? UserCache();

  bool get hasSession => _api.isLoggedIn;

  /// The last profile a server actually returned, read straight off disk —
  /// for `_AuthGateState._restoreSession` to boot into when the network is
  /// the thing that failed, not the token.
  Future<AppUser?> cachedUser() => _userCache.load();

  /// Every place above returns a fresh `AppUser`, this is also its only
  /// opportunity to become tomorrow's offline launch.
  ///
  /// The write is not awaited, mirroring `AppState.loadRequests`'s own
  /// `_requestCache.save`: the screen already has what it asked for, and a
  /// slow or unavailable disk write must not hold up login, `/me`, or a
  /// profile save waiting on it.
  Future<AppUser> _remember(Map<String, dynamic> json) async {
    _userCache.save(json);
    return AppUser.fromJson(json);
  }

  Future<RegisterOutcome> register({
    required String firstName,
    String? middleName,
    required String lastName,
    required int barangayId,
    String? streetAddress,
    required String phoneNumber,
    required String password,
    String accountType = 'head_of_family',
    String? organizationName,
  }) {
    return _api.register(
      firstName: firstName,
      middleName: middleName,
      lastName: lastName,
      barangayId: barangayId,
      streetAddress: streetAddress,
      phoneNumber: phoneNumber,
      password: password,
      accountType: accountType,
      organizationName: organizationName,
    );
  }

  /// Finishes registration with the code texted to the number. Returns the
  /// signed-in resident: the server issues a token here, so there is no second
  /// trip through the login screen.
  Future<AppUser> verifyPhone({
    required String phoneNumber,
    required String code,
  }) async {
    final json = await _api.verifyPhone(phoneNumber: phoneNumber, code: code);
    return _remember(json);
  }

  Future<VerificationDelivery?> resendVerificationCode({
    required String phoneNumber,
  }) {
    return _api.resendVerificationCode(phoneNumber: phoneNumber);
  }

  Future<List<BarangayOption>> barangays() async {
    final rows = await _api.getBarangays();
    return rows.map(BarangayOption.fromJson).toList();
  }

  /// Rebuilds the profile from a stored token on relaunch.
  Future<AppUser> currentUser() async {
    return _remember(await _api.me());
  }

  Future<AppUser> login({
    required String phoneNumber,
    required String password,
  }) async {
    final json =
        await _api.residentLogin(phoneNumber: phoneNumber, password: password);
    return _remember(json);
  }

  /// Second half of login: the code sent on the `mfa_required` refusal.
  /// Returns the signed-in resident, same as [login] would have — the server
  /// issues the token here instead.
  Future<AppUser> verifyLoginCode({
    required String challengeId,
    required String code,
  }) async {
    final json = await _api.verifyLoginCode(challengeId: challengeId, code: code);
    return _remember(json);
  }

  Future<VerificationDelivery?> resendLoginCode({
    required String challengeId,
  }) {
    return _api.resendLoginCode(challengeId: challengeId);
  }

  /// Saves the resident's own details and returns the refreshed profile, so
  /// the caller does not have to re-fetch `/me` to see the result. The phone
  /// number is not among them: it moves through [requestPhoneChange] and
  /// [verifyPhoneChange].
  Future<AppUser> updateProfile({
    String? firstName,
    String? middleName,
    String? lastName,
    String? streetAddress,
    bool? smsOptIn,
    int? barangayId,
  }) async {
    final json = await _api.updateProfile(
      firstName: firstName,
      middleName: middleName,
      lastName: lastName,
      streetAddress: streetAddress,
      smsOptIn: smsOptIn,
      barangayId: barangayId,
    );
    return _remember(json);
  }

  // --- Moving the phone number --------------------------------------------

  /// The new number and the current password; a code is texted to the NEW
  /// number. Nothing on the account changes until [verifyPhoneChange].
  Future<VerificationDelivery?> requestPhoneChange({
    required String phoneNumber,
    required String currentPassword,
  }) {
    return _api.requestPhoneChange(
      phoneNumber: phoneNumber,
      currentPassword: currentPassword,
    );
  }

  Future<VerificationDelivery?> resendPhoneChangeCode() {
    return _api.resendPhoneChangeCode();
  }

  /// The code that came back. Returns the refreshed profile with the new
  /// number, which also becomes tomorrow's offline launch.
  Future<AppUser> verifyPhoneChange({required String code}) async {
    return _remember(await _api.verifyPhoneChange(code: code));
  }

  // --- Forgotten password -------------------------------------------------

  Future<int> forgotPassword({required String phoneNumber}) {
    return _api.forgotPassword(phoneNumber: phoneNumber);
  }

  Future<String> verifyPasswordReset({
    required String phoneNumber,
    required String code,
  }) {
    return _api.verifyPasswordReset(phoneNumber: phoneNumber, code: code);
  }

  Future<void> resetPassword({
    required String phoneNumber,
    required String resetToken,
    required String password,
  }) {
    return _api.resetPassword(
      phoneNumber: phoneNumber,
      resetToken: resetToken,
      password: password,
    );
  }

  /// Uploads a new profile photo and returns the refreshed profile.
  Future<AppUser> setProfilePhoto({
    required List<int> bytes,
    required String fileName,
  }) async {
    final json = await _api.uploadProfilePhoto(bytes: bytes, fileName: fileName);
    return _remember(json);
  }

  Future<AppUser> removeProfilePhoto() async {
    return _remember(await _api.deleteProfilePhoto());
  }

  /// Image bytes for the avatar, or null when there is nothing stored.
  Future<List<int>?> profilePhoto(String residentId) =>
      _api.fetchProfilePhoto(residentId);

  /// The cache clears even if the server call fails — a resident who asked
  /// to log out on a shared phone must not leave their profile behind
  /// because the request that revoked the token also happened to time out.
  Future<void> logout() async {
    try {
      await _api.logout();
    } finally {
      await _userCache.clear();
    }
  }
}
