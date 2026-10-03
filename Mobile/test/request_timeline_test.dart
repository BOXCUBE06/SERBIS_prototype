// M26: `ServiceRequest.fromJson` used to set `timeline: const []`, and the
// Track card only drew the timeline block `if (request.timeline.isNotEmpty)`.
// So the app's main answer to "what is happening with my request?" existed
// only for rows created in the current session and disappeared on relaunch.
// The timeline is now derived from `status` + `created_at` + `updated_at`,
// which every row already carries.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

Map<String, dynamic> _row(
  String status, {
  String? createdAt = '2026-08-01T05:04:00.000000Z',
  String? updatedAt = '2026-08-01T07:30:00.000000Z',
}) =>
    <String, dynamic>{
      'request_id': 31,
      'service_id': 1,
      'description': 'Flooded street',
      'status': status,
      'can_cancel': status == 'Pending' || status == 'Booked',
      'created_at': createdAt,
      'updated_at': updatedAt,
    };

class _FakeApi extends ApiService {
  _FakeApi({this.rows = const <Map<String, dynamic>>[]});

  final List<Map<String, dynamic>> rows;
  int cancelled = 0;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => rows;

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<void> cancelRequest(int id) async {
    cancelled++;
  }
}

void main() {
  test('a server-loaded row has a timeline instead of an empty list', () {
    final request = ServiceRequest.fromJson(_row('Pending'));

    // The regression itself: this list was `const []` for every row that came
    // back from GET /service-requests, which is every row after a relaunch.
    expect(request.timelineFor(false), isNotEmpty);
  });

  test('the submitted step carries the row\'s real created_at', () {
    final request = ServiceRequest.fromJson(_row('Pending'));
    final steps = request.timelineFor(false);

    expect(steps.first.title, 'Request submitted');
    expect(steps.first.state, RequestStepState.done);
    // Formatted from created_at, so it is neither blank nor the old literal
    // 'Pending' placeholder.
    expect(steps.first.time, contains(':'));
    expect(steps.first.time, isNot(contains('Pending')));
  });

  test('timestamps are converted out of UTC', () {
    final request = ServiceRequest.fromJson(_row('Pending'));

    // Laravel serialises UTC. Without toLocal() every time would read eight
    // hours early in the Philippines. Asserted against the same conversion
    // rather than a fixed clock time so the test does not depend on the
    // machine's zone.
    expect(request.createdAt, DateTime.parse('2026-08-01T05:04:00.000000Z').toLocal());
    expect(request.createdAt!.isUtc, isFalse);
  });

  test('a pending row shows review as current and completion as pending', () {
    final steps = ServiceRequest.fromJson(_row('Pending')).timelineFor(false);

    expect(steps.length, 3);
    expect(steps[1].title, 'Under review by MDRRMO');
    expect(steps[1].state, RequestStepState.current);
    expect(steps[2].state, RequestStepState.pending);
  });

  test('a responding row moves the current step and stamps updated_at', () {
    final steps = ServiceRequest.fromJson(_row('Responding')).timelineFor(false);

    expect(steps[1].title, 'MDRRMO is responding');
    expect(steps[1].state, RequestStepState.current);
    expect(steps[1].time, isNot('Waiting'));
  });

  test('a resolved row ends at Completed with no step still pending', () {
    final steps = ServiceRequest.fromJson(_row('Resolved')).timelineFor(false);

    expect(steps.length, 2);
    expect(steps.last.title, 'Completed');
    expect(steps.last.state, RequestStepState.done);
    expect(steps.any((s) => s.state == RequestStepState.pending), isFalse);
  });

  test('a cancelled row ends at Cancelled and promises nothing further', () {
    final steps = ServiceRequest.fromJson(_row('Cancelled')).timelineFor(false);

    expect(steps.length, 2);
    expect(steps.last.title, 'Cancelled');
    expect(steps.last.state, RequestStepState.done);
    // The old hand-built list appended a cancellation step to whatever was
    // already there, so a cancelled request could still show "Completed" ahead
    // of it.
    expect(steps.any((s) => s.title == 'Completed'), isFalse);
  });

  test('a row with no usable timestamps says so instead of inventing one', () {
    final missing = ServiceRequest.fromJson(_row('Pending', createdAt: null));
    expect(missing.timelineFor(false).first.time, 'Time not recorded');

    final malformed = ServiceRequest.fromJson(_row('Pending', createdAt: 'not a date'));
    expect(malformed.createdAt, isNull);
    expect(malformed.timelineFor(false).first.time, 'Time not recorded');
  });

  test('an untouched row reports no second timestamp rather than repeating the first', () {
    // updated_at equal to created_at means nothing has happened to the row
    // since it was filed; presenting it as the moment the request moved would
    // be a claim the data does not support.
    final steps = ServiceRequest.fromJson(
      _row('Resolved', updatedAt: '2026-08-01T05:04:00.000000Z'),
    ).timelineFor(false);

    expect(steps.last.time, 'Time not recorded');
  });

  test('the steps are translated', () {
    final steps = ServiceRequest.fromJson(_row('Pending')).timelineFor(true);

    expect(steps.first.title, 'Naisumite ang kahilingan');
    expect(steps[1].title, 'Sinusuri ng MDRRMO');
    // The old timeline was built from English literals at submit time, so a
    // Filipino-speaking resident read English steps.
    expect(steps.first.title, isNot('Request submitted'));
  });

  test('a local cancellation stamps the time the timeline then shows', () async {
    final api = _FakeApi();
    final state = AppState(api);
    state.requests.add(ServiceRequest.fromJson(_row('Pending')));

    final ok = await state.cancelRequest(31);

    expect(ok, isTrue);
    expect(api.cancelled, 1);

    final steps = state.requests.single.timelineFor(false);
    expect(steps.last.title, 'Cancelled');
    // Stamped by cancelRequest, not carried over from the server's updated_at.
    expect(state.requests.single.updatedAt!.isAfter(DateTime(2026, 8, 1, 12)), isTrue);
    expect(steps.last.time, isNot('Time not recorded'));
  });

  testWidgets('a server-loaded open card shows its steps', (tester) async {
    // The model half above is worth nothing if the card keeps hiding the block.
    // This is the regression as a resident met it: relaunch the app, and the
    // request loaded from GET /service-requests had no timeline at all.
    final state = AppState(_FakeApi(rows: [_row('Pending')]));
    await state.loadRequests();

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

    expect(find.text('Request submitted'), findsOneWidget);
    expect(find.text('Under review by MDRRMO'), findsOneWidget);
    // The steps carry real times, not the old literal 'Pending' placeholder.
    expect(find.text('Pending'), findsNothing);
  });
}
