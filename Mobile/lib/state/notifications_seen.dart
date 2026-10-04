import 'package:shared_preferences/shared_preferences.dart';

import 'app_log.dart';

/// When the resident last opened the notifications sheet. Anything that moved
/// after it lights the bell's dot. Same shape as [UserCache]: a failure reads
/// as "never opened" rather than stopping the app.
class NotificationsSeen {
  static const _logArea = 'cache';
  static const _key = 'serbis.notifications.seen_at.v1';

  final Future<SharedPreferences> Function() _prefs;

  NotificationsSeen({Future<SharedPreferences> Function()? preferences})
      : _prefs = preferences ?? SharedPreferences.getInstance;

  Future<DateTime?> load() async {
    try {
      final raw = (await _prefs()).getString(_key);
      return raw == null ? null : DateTime.tryParse(raw);
    } catch (error) {
      AppLog.error(_logArea, 'read notifications seen', error: error, reason: 'treated as never opened');
      return null;
    }
  }

  Future<void> save(DateTime at) async {
    try {
      await (await _prefs()).setString(_key, at.toIso8601String());
    } catch (error) {
      AppLog.error(_logArea, 'write notifications seen', error: error, reason: 'dot may return next launch');
    }
  }
}
