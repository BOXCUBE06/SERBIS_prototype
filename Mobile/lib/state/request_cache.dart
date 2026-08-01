import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/request_models.dart';

/// What was on the Track screen the last time the server answered.
class CachedRequests {
  final List<ServiceRequest> requests;

  /// When the list was fetched, not when it was written. This is what the
  /// screen puts next to "Last updated".
  final DateTime fetchedAt;

  const CachedRequests({required this.requests, required this.fetchedAt});
}

/// Persists the request list so an offline launch shows what MDRRMO last said
/// instead of the "No requests yet" card.
///
/// `shared_preferences` rather than a file: it works on web as well as on
/// device, and this is a handful of rows, not a document store. Nothing secret
/// goes in here — the auth token lives in secure storage (M7) — but the rows do
/// name the resident's own requests, so the store is cleared on logout.
class RequestCache {
  static const _rowsKey = 'serbis.requests.cache.v1';
  static const _fetchedAtKey = 'serbis.requests.cache.fetched_at.v1';

  /// Injected under test so the cache can be exercised without the plugin.
  final Future<SharedPreferences> Function() _prefs;

  RequestCache({Future<SharedPreferences> Function()? preferences})
      : _prefs = preferences ?? SharedPreferences.getInstance;

  Future<void> save(List<ServiceRequest> requests, DateTime fetchedAt) async {
    // Rows with no server id were never filed. Persisting one would resurrect a
    // request MDRRMO has no record of, on a launch where the resident cannot
    // check.
    final rows = requests
        .where((request) => request.id != null)
        .map((request) => request.toCacheJson())
        .toList();

    try {
      final prefs = await _prefs();
      await prefs.setString(_rowsKey, jsonEncode(rows));
      await prefs.setString(_fetchedAtKey, fetchedAt.toIso8601String());
    } catch (_) {
      // A cache that cannot be written is a missing cache, not a failed
      // refresh. The list on screen is already correct.
    }
  }

  /// Null when there is nothing usable stored. An unreadable entry is treated
  /// as absent rather than thrown: the app must still start.
  Future<CachedRequests?> load() async {
    try {
      final prefs = await _prefs();
      final raw = prefs.getString(_rowsKey);
      if (raw == null || raw.isEmpty) return null;

      final decoded = jsonDecode(raw);
      if (decoded is! List) return null;

      final requests = decoded
          .map(ServiceRequestCache.fromCacheJson)
          .whereType<ServiceRequest>()
          .toList();
      if (requests.isEmpty) return null;

      final fetchedAt =
          DateTime.tryParse(prefs.getString(_fetchedAtKey) ?? '');
      // No timestamp means the screen cannot honestly date the rows, and rows
      // of unknown age are worse than none on a disaster-response screen.
      if (fetchedAt == null) return null;

      return CachedRequests(requests: requests, fetchedAt: fetchedAt);
    } catch (_) {
      return null;
    }
  }

  Future<void> clear() async {
    try {
      final prefs = await _prefs();
      await prefs.remove(_rowsKey);
      await prefs.remove(_fetchedAtKey);
    } catch (_) {
      // Nothing to do: a cache that cannot be cleared could not be read either.
    }
  }
}
