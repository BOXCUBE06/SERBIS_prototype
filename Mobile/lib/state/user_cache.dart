import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import 'account_store.dart';
import 'app_log.dart';

/// Persists the resident's own `/me` response so a relaunch with no signal
/// can still open into the app instead of stopping dead at the login screen.
///
/// Mirrors [RequestCache]: `shared_preferences`, cleared on logout, an
/// unreadable entry treated as absent rather than thrown — the app must
/// still start. The raw server JSON is stored as-is rather than a
/// reconstructed shape, so [AppUser.fromJson] is the only parser this ever
/// needs.
class UserCache {
  static const _logArea = 'cache';
  static const _key = 'serbis.user.cache.v1';

  final Future<SharedPreferences> Function() _prefs;

  UserCache({Future<SharedPreferences> Function()? preferences})
      : _prefs = preferences ?? SharedPreferences.getInstance;

  Future<void> save(Map<String, dynamic> json) async {
    try {
      final prefs = await _prefs();
      await prefs.setString(_key, jsonEncode(json));
    } catch (error) {
      AppLog.error(_logArea, 'write user cache', error: error,
          reason: 'profile not persisted');
    }
  }

  /// Null when there is nothing usable stored, including when the entry
  /// cannot be parsed — the caller falls back to the login screen either way.
  Future<AppUser?> load() async {
    try {
      final prefs = await _prefs();
      final raw = prefs.getString(_key);
      if (raw == null || raw.isEmpty) return null;

      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) return null;

      return AppUser.fromJson(decoded);
    } catch (error) {
      AppLog.error(_logArea, 'read user cache', error: error,
          reason: 'treated as absent');
      return null;
    }
  }

  Future<void> clear() async {
    try {
      final prefs = await _prefs();
      await prefs.remove(_key);
    } catch (error) {
      // Not merely cosmetic: this runs on logout, so a failure here leaves
      // one resident's profile on a shared phone.
      AppLog.error(_logArea, 'clear user cache', error: error,
          reason: 'previous profile may remain on device');
    }
  }
}
