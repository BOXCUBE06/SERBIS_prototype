import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

/// A locally-registered SERBIS account.
///
/// There is still no backend — accounts are created and validated entirely
/// on-device, persisted with `shared_preferences` so they survive app
/// restarts. Replace [UserStore] with real API calls once a backend exists;
/// the phone-number-as-username + password shape should map cleanly onto
/// most auth APIs.
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

/// Stores registered accounts, keyed by normalized (digits-only) phone
/// number. Persisted locally via `shared_preferences`.
class UserStore {
  static const _storageKey = 'serbis_users_v1';

  final Map<String, AppUser> _users = {};
  bool _loaded = false;

  /// Loads previously-registered accounts from local storage. Safe to call
  /// multiple times — only loads once.
  Future<void> load() async {
    if (_loaded) return;
    _loaded = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null || raw.isEmpty) return;
      final list = jsonDecode(raw) as List<dynamic>;
      for (final item in list) {
        final user = AppUser.fromJson(item as Map<String, dynamic>);
        _users[_normalize(user.phone)] = user;
      }
    } catch (_) {
      // If stored data is corrupt or unavailable, start with no accounts
      // rather than crashing the app.
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    final list = _users.values.map((u) => u.toJson()).toList();
    await prefs.setString(_storageKey, jsonEncode(list));
  }

  String _normalize(String phone) => phone.replaceAll(RegExp(r'[^0-9]'), '');

  /// Whether an account with this phone number already exists.
  bool exists(String phone) => _users.containsKey(_normalize(phone));

  /// Registers a new account. Returns false if the phone number is already
  /// registered.
  Future<bool> register(AppUser user) async {
    final key = _normalize(user.phone);
    if (_users.containsKey(key)) return false;
    _users[key] = user.copyWith(phone: key);
    await _save();
    return true;
  }

  /// Validates credentials, returning the matching [AppUser] or null if the
  /// phone number isn't registered or the password doesn't match.
  AppUser? validate(String phone, String password) {
    final user = _users[_normalize(phone)];
    if (user == null) return null;
    return user.password == password ? user : null;
  }
}
