import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../data/hotlines.dart';
import 'app_log.dart';

/// The last hotline list the server sent, so a launch with no signal shows
/// MDRRMO's current numbers rather than the ones compiled into the app.
/// Same pattern as `BorrowCache`, with one difference: hotlines are not
/// personal, so this is never cleared on logout.
class HotlineCache {
  static const _logArea = 'cache';
  // v2: rows no longer carry icon/colour. v1 is deleted on the next save.
  static const _rowsKey = 'serbis.hotlines.cache.v2';
  static const _oldKey = 'serbis.hotlines.cache.v1';

  final Future<SharedPreferences> Function() _prefs;

  HotlineCache({Future<SharedPreferences> Function()? preferences})
      : _prefs = preferences ?? SharedPreferences.getInstance;

  Future<void> save(List<Hotline> hotlines) async {
    try {
      final prefs = await _prefs();
      await prefs.remove(_oldKey);
      await prefs.setString(
        _rowsKey,
        jsonEncode(hotlines.map((h) => h.toCacheJson()).toList()),
      );
    } catch (error) {
      AppLog.error(_logArea, 'write hotline cache', error: error,
          reason: '${hotlines.length} rows not persisted');
    }
  }

  /// Fetches the server list and caches it. Null when the fetch fails or has
  /// no usable rows, so the caller keeps whatever list it was showing.
  Future<List<Hotline>?> refresh(
      Future<List<Map<String, dynamic>>> Function() fetch) async {
    try {
      final fresh =
          (await fetch()).map(Hotline.fromJson).whereType<Hotline>().toList();
      if (fresh.isEmpty) return null;
      await save(fresh);
      return fresh;
    } catch (_) {
      AppLog.warn(_logArea, 'fetch hotlines', reason: 'kept current list');
      return null;
    }
  }

  /// Null when nothing usable is stored — the caller falls back to kHotlines.
  Future<List<Hotline>?> load() async {
    try {
      final prefs = await _prefs();
      final raw = prefs.getString(_rowsKey);
      if (raw == null || raw.isEmpty) return null;

      final decoded = jsonDecode(raw);
      if (decoded is! List) return null;

      final hotlines = decoded.map(Hotline.fromJson).whereType<Hotline>().toList();
      return hotlines.isEmpty ? null : hotlines;
    } catch (error) {
      AppLog.error(_logArea, 'read hotline cache', error: error,
          reason: 'treated as empty');
      return null;
    }
  }
}
