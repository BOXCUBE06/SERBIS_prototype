// M23: the app had no concept of being offline. `AppState.requests` was
// in-memory only, so an offline launch showed an empty Track screen with the
// cheerful "No requests yet" card, and a failed fetch was indistinguishable
// from having filed nothing. This is a disaster-response app: the moments it
// matters most are exactly when the tower is congested or down.
//
// The list is now persisted and rehydrated, and "offline" is derived from what
// actually happened to a request rather than from the radio link.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_cache.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/offline_banner.dart';

class _FakeApi extends ApiService {
  _FakeApi({this.rows = const [], this.error});

  List<Map<String, dynamic>> rows;
  Object? error;
  int calls = 0;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async {
    calls++;
    if (error != null) throw error!;
    return rows;
  }

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async => [];
}

Map<String, dynamic> _row(int id, String status) => <String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'description': 'Flooded street',
      'status': status,
      'created_at': '2026-08-01T05:04:00.000000Z',
      'updated_at': '2026-08-01T05:04:00.000000Z',
    };

RequestCache _cache() {
  // The in-memory implementation shared_preferences ships for tests; each test
  // sets its own initial values, so the caches do not leak into each other.
  return RequestCache(preferences: SharedPreferences.getInstance);
}

ServiceRequest _pending() => const ServiceRequest(
      id: null,
      serviceId: 1,
      description: 'Just submitted',
      type: ServiceType.ambulance,
      refNo: '',
      status: ReqStatus.review,
      metaLines: <String>[],
    );

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('the request list survives a relaunch', () {
    test('a successful fetch is written to the device', () async {
      final api = _FakeApi(rows: [_row(1, 'Pending')]);
      final state = AppState(api, requestCache: _cache());

      await state.loadRequests();
      // The write is not awaited by loadRequests; give it the microtask it
      // needs before reading it back.
      await Future<void>.delayed(Duration.zero);

      final stored = await _cache().load();
      expect(stored, isNotNull);
      expect(stored!.requests.single.id, 1);
      expect(stored.fetchedAt, isNotNull);
    });

    test('a relaunch with no network shows the last list the server sent',
        () async {
      final first = AppState(_FakeApi(rows: [_row(1, 'Pending')]), requestCache: _cache());
      await first.loadRequests();
      await Future<void>.delayed(Duration.zero);

      // Second launch: the network is gone.
      final offline = AppState(
        _FakeApi(error: const ApiException('No connection')),
        requestCache: _cache(),
      );
      await offline.hydrateRequests();

      expect(offline.requests.single.id, 1);
      expect(offline.requestsFromCache, isTrue);
      expect(offline.requestsFetchedAt, isNotNull);
    });

    test('a fetch that already landed is not overwritten by the cache',
        () async {
      final seed = AppState(_FakeApi(rows: [_row(1, 'Pending')]), requestCache: _cache());
      await seed.loadRequests();
      await Future<void>.delayed(Duration.zero);

      final state = AppState(_FakeApi(rows: [_row(2, 'Resolved')]), requestCache: _cache());
      await state.loadRequests();
      await state.hydrateRequests();

      // The server's answer is newer than anything on disk, by definition.
      expect(state.requests.single.id, 2);
      expect(state.requestsFromCache, isFalse);
    });

    test('an unsent row is never persisted', () async {
      final state = AppState(_FakeApi(rows: [_row(1, 'Pending')]), requestCache: _cache());
      state.requests.add(_pending());
      await state.loadRequests();
      await Future<void>.delayed(Duration.zero);

      final stored = await _cache().load();
      // Restoring it would claim a request MDRRMO has no record of, on a launch
      // where the resident cannot check.
      expect(stored!.requests.length, 1);
      expect(stored.requests.single.id, 1);

      // Asserted on what was written, not only on what comes back: the reader
      // drops id-less rows too, so checking `load()` alone passes even if the
      // writer stores them.
      final raw = (await SharedPreferences.getInstance())
          .getString('serbis.requests.cache.v1');
      expect(raw, isNot(contains('Just submitted')));
    });

    test('logging out clears the rows off the device', () async {
      final state = AppState(_FakeApi(rows: [_row(1, 'Pending')]), requestCache: _cache());
      await state.loadRequests();
      await Future<void>.delayed(Duration.zero);

      await state.clearRequestCache();

      expect(state.requests, isEmpty);
      expect(await _cache().load(), isNull);
    });

    test('a cached row keeps its status, ref and timestamps', () async {
      final seed = AppState(_FakeApi(rows: [_row(9, 'Responding')]), requestCache: _cache());
      await seed.loadRequests();
      await Future<void>.delayed(Duration.zero);

      final state = AppState(_FakeApi(), requestCache: _cache());
      await state.hydrateRequests();
      final restored = state.requests.single;

      expect(restored.status, ReqStatus.scheduled);
      expect(restored.refNo, 'TXN-000009');
      expect(restored.createdAt, isNotNull);
      // Which means the timeline still works with no network.
      expect(restored.timelineFor(false), isNotEmpty);
    });

    test('a corrupt cache is treated as no cache, not as a crash', () async {
      SharedPreferences.setMockInitialValues({
        'serbis.requests.cache.v1': 'not json',
        'serbis.requests.cache.fetched_at.v1': '2026-08-01T09:00:00.000',
      });

      final state = AppState(_FakeApi(), requestCache: _cache());
      await state.hydrateRequests();

      expect(state.requests, isEmpty);
    });

    test('rows with no fetch time are not shown at all', () async {
      // Rows of unknown age are worse than no rows on a screen a resident reads
      // during an emergency.
      SharedPreferences.setMockInitialValues({
        'serbis.requests.cache.v1':
            '[{"id":1,"type":"ambulance","ref_no":"SR-1","status":"review","meta_lines":[]}]',
      });

      final state = AppState(_FakeApi(), requestCache: _cache());
      await state.hydrateRequests();

      expect(state.requests, isEmpty);
    });
  });

  group('offline is derived from what happened, not from the radio', () {
    test('a network failure sets it', () async {
      final state = AppState(
        _FakeApi(error: const ApiException('No connection')),
        requestCache: _cache(),
      );

      await state.loadRequests();

      expect(state.isOffline, isTrue);
    });

    test('a server that answers with an error is not offline', () async {
      // The connection is fine -- the server rejected the request. A "no
      // connection" banner over a 500 sends the resident to check their signal.
      final state = AppState(
        _FakeApi(error: const ApiException('Server error', statusCode: 500)),
        requestCache: _cache(),
      );

      await state.loadRequests();

      expect(state.isOffline, isFalse);
    });

    test('a successful fetch clears it', () async {
      final api = _FakeApi(error: const ApiException('No connection'));
      final state = AppState(api, requestCache: _cache());
      await state.loadRequests();
      expect(state.isOffline, isTrue);

      api.error = null;
      api.rows = [_row(1, 'Pending')];
      await state.loadRequests();

      expect(state.isOffline, isFalse);
      expect(state.requestsFromCache, isFalse);
    });

    test('a silent poll still raises the flag and notifies', () async {
      final state = AppState(
        _FakeApi(error: const ApiException('No connection')),
        requestCache: _cache(),
      );
      var notifications = 0;
      state.addListener(() => notifications++);

      // Silent means no snackbar, not no banner: the 45-second poll is how a
      // resident who is not touching the app finds out the network is gone.
      await state.loadRequests(silent: true);

      expect(state.isOffline, isTrue);
      expect(state.takeError(), isNull);
      expect(notifications, greaterThan(0));
    });
  });

  group('what the resident sees', () {
    testWidgets('the banner names the problem and dates the data',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: OfflineBanner(
            filipino: false,
            lastUpdated: DateTime(2026, 8, 1, 9, 4),
          ),
        ),
      ));

      expect(find.textContaining("Requests can't be sent"), findsOneWidget);
      expect(find.textContaining('Last updated'), findsOneWidget);
    });

    testWidgets('with nothing ever loaded it says that instead of a date',
        (tester) async {
      await tester.pumpWidget(const MaterialApp(
        home: Scaffold(body: OfflineBanner(filipino: false)),
      ));

      expect(
        find.text('Nothing has been loaded on this device yet.'),
        findsOneWidget,
      );
    });

    testWidgets('Track marks cached rows as saved copies', (tester) async {
      // Seeded through the store rather than by running a fetch: inside
      // testWidgets the clock is fake, and awaiting the cache's unawaited write
      // would hang the test rather than fail it.
      SharedPreferences.setMockInitialValues({
        'serbis.requests.cache.v1': jsonEncode([
          <String, dynamic>{
            'id': 1,
            'service_id': 1,
            'type': 'ambulance',
            'ref_no': 'SR-1',
            'status': 'review',
            'meta_lines': <String>[],
            'created_at': '2026-08-01T05:04:00.000Z',
          }
        ]),
        'serbis.requests.cache.fetched_at.v1': '2026-08-01T09:00:00.000',
      });

      final state = AppState(_FakeApi(), requestCache: _cache());
      await state.hydrateRequests();

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: TrackScreen(
            appState: state,
            onOpenNotifications: () {},
            onOpenProfile: () {},
          ),
        ),
      ));

      expect(find.textContaining('Saved copy'), findsOneWidget);
      // And not the empty state, which is what an offline launch used to show.
      expect(find.text('No requests yet'), findsNothing);
    });

    testWidgets('a fresh list carries no saved-copy note', (tester) async {
      final state = AppState(_FakeApi(rows: [_row(1, 'Pending')]), requestCache: _cache());
      await state.loadRequests();

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: TrackScreen(
            appState: state,
            onOpenNotifications: () {},
            onOpenProfile: () {},
          ),
        ),
      ));

      expect(find.textContaining('Saved copy'), findsNothing);
    });
  });
}
