// M21: the request list used to be fetched once in `initState` and never
// again, so a dispatcher could move a request Pending -> Responding ->
// Resolved and the app would show "Under review" until the process was killed.
// These cover the store half — the guards that make repeated fetching safe.
// The widget half (pull-to-refresh, the poll, the resume) is in main.dart and
// is not exercised here; see the task doc for what remains unverified.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  _FakeApi({this.rows, this.error, this.delay = Duration.zero});

  List<Map<String, dynamic>>? rows;
  Object? error;
  Duration delay;
  int calls = 0;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async {
    calls++;
    if (delay > Duration.zero) {
      await Future<void>.delayed(delay);
    }
    if (error != null) {
      throw error!;
    }
    return rows ?? <Map<String, dynamic>>[];
  }

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    return <Map<String, dynamic>>[];
  }
}

Map<String, dynamic> _row(int id, String status) => <String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'description': 'Flooded street',
      'status': status,
    };

/// A request that has been optimistically added but has no server id yet —
/// what `addRequest` inserts while its POST is in flight.
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
  test('a refetch picks up a status the dispatcher changed', () async {
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);

    await state.loadRequests();
    expect(state.requests.single.status, ReqStatus.review);

    api.rows = [_row(1, 'Responding')];
    await state.loadRequests();

    expect(state.requests.single.status, ReqStatus.scheduled);
    expect(api.calls, 2);
  });

  test('a refetch does not wipe a request that has no server id yet', () async {
    // The server does not know about an in-flight submission, so a refresh
    // landing mid-POST would otherwise clear it off the screen — and
    // `addRequest` would then never find the object it holds to swap for the
    // confirmed row.
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);
    final pending = _pending();
    state.requests.add(pending);

    await state.loadRequests();

    expect(state.requests, hasLength(2));
    expect(identical(state.requests.first, pending), isTrue);
    expect(state.requests.last.id, 1);
  });

  test('overlapping fetches collapse into one', () async {
    // The poll, a tab switch and a resume can all fire within a second. Two
    // fetches both clearing the same list is how rows go missing.
    final api = _FakeApi(
      rows: [_row(1, 'Pending')],
      delay: const Duration(milliseconds: 40),
    );
    final state = AppState(api);

    await Future.wait<void>([
      state.loadRequests(),
      state.loadRequests(),
      state.loadRequests(),
    ]);

    expect(api.calls, 1);
    expect(state.requests, hasLength(1));
  });

  test('maxAge skips a fetch when the list is still fresh', () async {
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);

    await state.loadRequests();
    await state.loadRequests(maxAge: const Duration(seconds: 15));

    expect(api.calls, 1);
  });

  test('maxAge does not skip a fetch that never happened', () async {
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);

    await state.loadRequests(maxAge: const Duration(seconds: 15));

    expect(api.calls, 1);
  });

  test('a failed fetch leaves an already-loaded list alone', () async {
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);

    await state.loadRequests();
    api.error = const ApiException('Cannot connect to server.');
    await state.loadRequests();

    expect(state.requests, hasLength(1));
  });

  test('a silent fetch failure raises no error for the shell to show', () async {
    // A 45-second poll with no signal must not stack a snackbar over the screen
    // every interval.
    final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
    final state = AppState(api);

    await state.loadRequests(silent: true);

    expect(state.takeError(), isNull);
  });

  test('a pull-to-refresh failure does raise an error', () async {
    // The resident asked, so they are owed an answer.
    final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
    final state = AppState(api);

    await state.loadRequests();

    expect(state.takeError(), 'Cannot connect to server.');
  });

  test('a failed fetch does not stamp the freshness clock', () async {
    // Otherwise a failure would make the list look fresh and maxAge would skip
    // the retry that a tab switch is meant to trigger.
    final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
    final state = AppState(api);

    await state.loadRequests(silent: true);
    expect(state.requestsFetchedAt, isNull);

    api.error = null;
    api.rows = [_row(1, 'Pending')];
    await state.loadRequests(silent: true, maxAge: const Duration(seconds: 15));

    expect(api.calls, 2);
  });

  testWidgets('pulling down the Track screen refetches', (tester) async {
    // The store guards above are worth nothing if nothing is wired to them.
    // Removing `onRefresh` from TrackScreen fails this test, so it covers the
    // wiring rather than the store.
    final api = _FakeApi(rows: [_row(1, 'Pending')]);
    final state = AppState(api);
    await state.loadRequests();
    expect(api.calls, 1);

    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: TrackScreen(
          appState: state,
          onOpenNotifications: () {},
          onOpenProfile: () {},
          onOpenMyLoans: () {},
          onBrowseServices: () {},
        ),
      ),
    ));

    await tester.fling(find.byType(RefreshIndicator), const Offset(0, 350), 1000);
    await tester.pumpAndSettle();

    expect(api.calls, 2);
  });

  testWidgets('a Track screen with nothing to scroll can still be pulled',
      (tester) async {
    // The default physics refuse to overscroll a list that fits, which would
    // kill the pull on the empty state — the screen where a refresh matters
    // most. Today the list inherits always-scrollable for free by being
    // `primary`; this fails the moment it stops being, so it is the guard on a
    // change that would otherwise break the pull silently.
    //
    // The 600px-tall default test viewport hides all of this: the empty state
    // overflows it and scrolls regardless of physics. Use a phone-shaped one
    // and assert the list really has nowhere to scroll before pulling it —
    // without that assertion this test passes against physics that cannot
    // overscroll at all.
    tester.view.physicalSize = const Size(1080, 3000);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    final api = _FakeApi(rows: <Map<String, dynamic>>[]);
    final state = AppState(api);
    await state.loadRequests();
    expect(state.requests, isEmpty);

    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: TrackScreen(
          appState: state,
          onOpenNotifications: () {},
          onOpenProfile: () {},
          onOpenMyLoans: () {},
          onBrowseServices: () {},
        ),
      ),
    ));

    expect(
      tester.state<ScrollableState>(find.byType(Scrollable)).position.maxScrollExtent,
      0,
      reason: 'the fixture must not scroll, or it does not test the physics',
    );

    await tester.fling(find.byType(RefreshIndicator), const Offset(0, 350), 1000);
    await tester.pumpAndSettle();

    expect(api.calls, 2);
  });
}
