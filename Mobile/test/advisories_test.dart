// GET /api/advisories has existed since the SMS blast logging work and no
// client ever read it, so every warning the MDRRMO texted was invisible in the
// app that exists to carry it. The bell sheet now shows them, above the
// resident's own request updates.
//
// The rule these cover: an empty advisory list and an unreachable server look
// identical on screen and mean opposite things, so the sheet must never render
// a failure as "no advisories have been sent to you".

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/advisory.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/shared_widgets.dart';

class _FakeApi extends ApiService {
  _FakeApi({this.rows = const <Map<String, dynamic>>[], this.error});

  List<Map<String, dynamic>> rows;
  Object? error;
  int calls = 0;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getAdvisories() async {
    calls++;
    if (error != null) throw error!;
    return rows;
  }
}

Map<String, dynamic> _row(
  int id,
  String body, {
  String? createdAt = '2026-08-01T05:04:00.000000Z',
  String? barangay = 'San Fabian',
}) =>
    <String, dynamic>{
      'sms_log_id': id,
      'target_area_id': 1,
      'message_body': body,
      'status': 'Sent',
      'created_at': createdAt,
      if (barangay != null)
        'barangay': <String, dynamic>{'barangay_id': 1, 'barangay_name': barangay},
    };

Future<void> _pumpSheet(
  WidgetTester tester, {
  List<Advisory> advisories = const [],
  String? advisoriesError,
  List<ServiceRequest> requests = const [],
  bool filipino = false,
}) async {
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: NotificationsSheet(
        advisories: advisories,
        advisoriesError: advisoriesError,
        requests: requests,
        filipino: filipino,
        // The fixtures are dated August 2026; keep them inside the 30-day window.
        now: DateTime.utc(2026, 8, 10),
      ),
    ),
  ));
}

ServiceRequest _request() => ServiceRequest.fromJson(<String, dynamic>{
      'request_id': 7,
      'service_id': 1,
      'description': 'Flooded street',
      'status': 'Pending',
      'created_at': '2026-08-01T05:04:00.000000Z',
      'updated_at': '2026-08-01T05:04:00.000000Z',
    });

void main() {
  group('the model', () {
    test('reads the blast body and converts the timestamp out of UTC', () {
      final advisory = Advisory.fromJson(_row(3, 'Evacuate low-lying areas.'));

      expect(advisory.id, 3);
      expect(advisory.message, 'Evacuate low-lying areas.');
      expect(advisory.barangay, 'San Fabian');
      // Laravel serialises UTC; without toLocal() a warning sent this morning
      // reads as yesterday evening in the Philippines.
      expect(advisory.sentAt,
          DateTime.parse('2026-08-01T05:04:00.000000Z').toLocal());
      expect(advisory.sentAt!.isUtc, isFalse);
    });

    test('a row with no barangay or date still loads', () {
      final advisory = Advisory.fromJson(
        _row(3, 'Class suspension.', createdAt: null, barangay: null),
      );

      expect(advisory.barangay, isNull);
      expect(advisory.sentAt, isNull);
      expect(advisory.isReadable, isTrue);
    });

    test('a row with no message is not readable', () {
      expect(Advisory.fromJson(_row(3, '')).isReadable, isFalse);
    });
  });

  group('the store', () {
    test('loads the feed newest first and drops unreadable rows', () async {
      final api = _FakeApi(rows: [
        _row(1, 'Older warning', createdAt: '2026-07-01T05:00:00.000000Z'),
        _row(2, '', createdAt: '2026-08-02T05:00:00.000000Z'),
        _row(3, 'Newer warning', createdAt: '2026-08-01T05:00:00.000000Z'),
      ]);
      final state = AppState(api);

      await state.loadAdvisories();

      // The empty-bodied row is gone: an empty card in this list reads as an
      // alert nobody can act on.
      expect(state.advisories.map((a) => a.message),
          ['Newer warning', 'Older warning']);
      expect(state.advisoriesError, isNull);
      expect(state.advisoriesLoading, isFalse);
    });

    test('a failed fetch records the error and keeps what was loaded', () async {
      final api = _FakeApi(rows: [_row(1, 'Evacuate low-lying areas.')]);
      final state = AppState(api);

      await state.loadAdvisories();
      expect(state.advisories, hasLength(1));

      // The regression this guards: clearing on failure turns a lost signal
      // into "no advisories", in the one list read to find out whether a
      // warning was issued.
      api.error = const ApiException('Cannot connect to server.');
      await state.loadAdvisories();

      expect(state.advisories, hasLength(1));
      expect(state.advisoriesError, 'Cannot connect to server.');
    });

    test('a recovered fetch clears the error', () async {
      final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
      final state = AppState(api);

      await state.loadAdvisories();
      expect(state.advisoriesError, isNotNull);

      api
        ..error = null
        ..rows = [_row(1, 'All clear.')];
      await state.loadAdvisories();

      expect(state.advisoriesError, isNull);
      expect(state.advisories.single.message, 'All clear.');
    });
  });

  group('the notifications sheet', () {
    testWidgets('shows the blast the agency actually sent', (tester) async {
      await _pumpSheet(tester, advisories: [
        Advisory(
          id: 1,
          message: 'Evacuate low-lying areas before 6 PM.',
          barangay: 'San Fabian',
          sentAt: DateTime(2026, 8, 1, 13, 4),
        ),
      ]);

      // Verbatim, untranslated and untruncated: the instruction to act on is
      // often the last line.
      expect(find.text('Evacuate low-lying areas before 6 PM.'), findsOneWidget);
      expect(find.textContaining('San Fabian'), findsOneWidget);
      expect(find.text('No advisories have been sent to you.'), findsNothing);
    });

    testWidgets('advisories sit above the resident\'s own request updates',
        (tester) async {
      await _pumpSheet(
        tester,
        advisories: [
          Advisory(
            id: 1,
            message: 'Evacuate low-lying areas before 6 PM.',
            sentAt: DateTime(2026, 8, 1, 13, 4),
          ),
        ],
        requests: [_request()],
      );

      // A warning outranks a status change. Burying one under "Request
      // resolved" is the failure this ordering exists to prevent.
      final advisory =
          tester.getTopLeft(find.text('Evacuate low-lying areas before 6 PM.'));
      final request = tester.getTopLeft(find.textContaining('TXN-000007'));
      expect(advisory.dy, lessThan(request.dy));
    });

    testWidgets('an empty feed says none were sent', (tester) async {
      await _pumpSheet(tester);

      expect(find.text('No advisories have been sent to you.'), findsOneWidget);
    });

    testWidgets('a failed fetch never reads as an all-clear', (tester) async {
      await _pumpSheet(tester, advisoriesError: 'Cannot connect to server.');

      expect(
        find.text(
          "Couldn't load advisories. This does not mean none were sent — check with your barangay.",
        ),
        findsOneWidget,
      );
      // The whole point: these two states must not be confusable.
      expect(find.text('No advisories have been sent to you.'), findsNothing);
    });

    testWidgets('an advisory with no date says so rather than inventing one',
        (tester) async {
      await _pumpSheet(tester, advisories: [
        const Advisory(id: 1, message: 'Class suspension.'),
      ]);

      expect(find.text('Date not recorded'), findsOneWidget);
    });

    testWidgets('is translated', (tester) async {
      await _pumpSheet(tester, filipino: true);

      expect(find.text('Wala pang abisong ipinadala sa iyo.'), findsOneWidget);
      expect(find.text('Mga abiso ng MDRRMO'), findsOneWidget);
    });
  });
}
