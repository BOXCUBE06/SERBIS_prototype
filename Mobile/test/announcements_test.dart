// M19: the Home screen's "Announcements" section was two fixed tiles built
// from translation keys -- an invented "Today · 8:12 AM" and a weather advisory
// written months earlier -- one of them permanently flagged NEW. The bell
// carried a permanent unread dot and opened a sheet holding one hardcoded
// welcome message. A resident who tapped it during a flood read
// "We're glad to have you with Echague MDRRMO".
//
// Home now lists what MDRRMO has actually published, and the sheet lists the
// only real updates the app has: the state of the resident's own requests.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/dashboard_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/material_cache.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/shared_widgets.dart';

class _FakeApi extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async => <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async =>
      <Map<String, dynamic>>[];
}

InfoMaterial _material(int id, String title, {DateTime? publishedAt}) =>
    InfoMaterial(
      id: id,
      title: title,
      fileType: 'pdf',
      sizeBytes: 1024 * 1024,
      url: 'https://example.test/$id.pdf',
      publishedAt: publishedAt,
    );

Future<AppState> _pumpHome(
  WidgetTester tester, {
  List<InfoMaterial> materials = const [],
  bool loading = false,
  String? error,
  bool fromCache = false,
}) async {
  final state = AppState(_FakeApi());
  state.materials.addAll(materials);
  state.materialsLoading = loading;
  state.materialsError = error;
  state.materialsFromCache = fromCache;

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: HomeScreen(
        appState: state,
        onOpenTrack: () {},
        onOpenLibrary: () {},
        onOpenProfile: () {},
        onOpenNotifications: () {},
        onOpenServices: () {},
        onOpenService: (_) {},
      ),
    ),
  ));

  return state;
}

Future<void> _pumpSheet(
  WidgetTester tester,
  List<ServiceRequest> requests, {
  bool filipino = false,
}) async {
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: NotificationsSheet(requests: requests, filipino: filipino),
    ),
  ));
}

ServiceRequest _request({
  required String status,
  int id = 7,
  String? createdAt = '2026-08-01T05:04:00.000000Z',
  String? updatedAt,
}) =>
    ServiceRequest.fromJson(<String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'description': 'Flooded street',
      'status': status,
      'created_at': createdAt,
      'updated_at': updatedAt ?? createdAt,
    });

void main() {
  group('the model carries a real publication date', () {
    test('fromJson reads created_at and converts it out of UTC', () {
      final material = InfoMaterial.fromJson(const <String, dynamic>{
        'files_id': 3,
        'title': 'Flood preparedness guide',
        'file_type': 'PDF',
        'file_size': 2048,
        'full_url': 'https://example.test/3.pdf',
        'created_at': '2026-07-28T23:10:00.000000Z',
      });

      expect(material.publishedAt,
          DateTime.parse('2026-07-28T23:10:00.000000Z').toLocal());
      expect(material.publishedAt!.isUtc, isFalse);
    });

    test('a row with no created_at has no date rather than a made-up one', () {
      final material = InfoMaterial.fromJson(const <String, dynamic>{
        'files_id': 3,
        'title': 'Untitled',
      });

      expect(material.publishedAt, isNull);
    });

    test('the offline index round-trips the publication date', () {
      final entry = CachedMaterial(
        id: 3,
        title: 'Flood preparedness guide',
        fileType: 'pdf',
        sizeBytes: 2048,
        path: '/tmp/3.pdf',
        savedAt: DateTime(2026, 8, 1, 9),
        publishedAt: DateTime(2026, 7, 28, 7, 10),
      );

      final restored = CachedMaterial.fromJson(entry.toJson());

      expect(restored!.publishedAt, entry.publishedAt);
      // The Library renders this object when the server is unreachable, so the
      // date has to survive the conversion too.
      expect(restored.toMaterial().publishedAt, entry.publishedAt);
    });

    test('an index written before dates existed still loads', () {
      // Upgrading the app must not empty a resident's saved materials.
      final restored = CachedMaterial.fromJson(const <String, dynamic>{
        'id': 3,
        'title': 'Flood preparedness guide',
        'file_type': 'pdf',
        'size': 2048,
        'path': '/tmp/3.pdf',
        'saved_at': '2026-08-01T09:00:00.000',
      });

      expect(restored, isNotNull);
      expect(restored!.publishedAt, isNull);
    });
  });

  group('Home announcements', () {
    testWidgets('lists what MDRRMO published, not the placeholder copy',
        (tester) async {
      await _pumpHome(tester, materials: [
        _material(1, 'Evacuation map 2026', publishedAt: DateTime(2026, 7, 30, 8)),
      ]);

      expect(find.text('Evacuation map 2026'), findsOneWidget);
      // The two hardcoded tiles this replaced.
      expect(find.text('New materials uploaded'), findsNothing);
      expect(find.text('Weather advisory'), findsNothing);
      expect(find.text('Today · 8:12 AM'), findsNothing);
    });

    testWidgets('nothing is flagged NEW any more', (tester) async {
      // The badge was hardcoded true, so it was on for every resident on every
      // launch forever.
      await _pumpHome(tester, materials: [
        _material(1, 'Evacuation map 2026', publishedAt: DateTime(2026, 7, 30, 8)),
      ]);

      expect(find.text('NEW'), findsNothing);
    });

    testWidgets('shows the newest two, newest first', (tester) async {
      await _pumpHome(tester, materials: [
        _material(1, 'Oldest', publishedAt: DateTime(2026, 7, 1, 8)),
        _material(2, 'Newest', publishedAt: DateTime(2026, 7, 31, 8)),
        _material(3, 'Middle', publishedAt: DateTime(2026, 7, 15, 8)),
      ]);

      expect(find.text('Newest'), findsOneWidget);
      expect(find.text('Middle'), findsOneWidget);
      expect(find.text('Oldest'), findsNothing);

      final newest = tester.getTopLeft(find.text('Newest'));
      final middle = tester.getTopLeft(find.text('Middle'));
      expect(newest.dy, lessThan(middle.dy));
    });

    testWidgets('an undated material sinks and says so', (tester) async {
      await _pumpHome(tester, materials: [
        _material(1, 'Undated'),
        _material(2, 'Dated', publishedAt: DateTime(2026, 7, 31, 8)),
      ]);

      final dated = tester.getTopLeft(find.text('Dated'));
      final undated = tester.getTopLeft(find.text('Undated'));
      expect(dated.dy, lessThan(undated.dy));
      expect(find.text('Date not recorded'), findsOneWidget);
    });

    testWidgets('an empty catalogue says nothing has been published',
        (tester) async {
      await _pumpHome(tester);

      expect(find.text('MDRRMO has not published anything yet.'), findsOneWidget);
    });

    testWidgets('a failed fetch says so instead of showing an empty section',
        (tester) async {
      await _pumpHome(tester, error: 'Network unreachable');

      expect(find.text("Couldn't load announcements."), findsOneWidget);
      expect(find.text('MDRRMO has not published anything yet.'), findsNothing);
    });

    testWidgets('saved copies are labelled as not refreshed', (tester) async {
      await _pumpHome(
        tester,
        materials: [_material(1, 'Evacuation map 2026', publishedAt: DateTime(2026, 7, 30, 8))],
        fromCache: true,
      );

      expect(find.text('Saved copies — not refreshed from MDRRMO.'), findsOneWidget);
    });

    testWidgets('the header bell carries no permanent unread dot',
        (tester) async {
      await _pumpHome(tester);

      // The dot was an amber circle drawn over the bell on every screen.
      final dot = find.byWidgetPredicate((widget) =>
          widget is Container &&
          widget.decoration is BoxDecoration &&
          (widget.decoration as BoxDecoration).color == AppColors.amber600 &&
          (widget.decoration as BoxDecoration).shape == BoxShape.circle);

      expect(dot, findsNothing);
    });
  });

  group('Notifications sheet', () {
    testWidgets('with no requests it says so, without a welcome message',
        (tester) async {
      await _pumpSheet(tester, const []);

      expect(find.text('No updates yet'), findsOneWidget);
      expect(find.textContaining('glad to have you'), findsNothing);
    });

    testWidgets('lists the resident\'s own requests with their real time',
        (tester) async {
      await _pumpSheet(tester, [_request(status: 'Responding')]);

      // The app's own vocabulary for a Responding row: `status.scheduled`.
      expect(find.textContaining('Scheduled'), findsOneWidget);
      expect(find.textContaining('SR-7'), findsOneWidget);
      // A real timestamp, not the old fixed copy.
      expect(find.textContaining(':'), findsWidgets);
    });

    testWidgets('newest movement first', (tester) async {
      await _pumpSheet(tester, [
        _request(
          id: 1,
          status: 'Pending',
          createdAt: '2026-07-01T05:00:00.000000Z',
        ),
        _request(
          id: 2,
          status: 'Resolved',
          createdAt: '2026-07-01T05:00:00.000000Z',
          updatedAt: '2026-07-30T05:00:00.000000Z',
        ),
      ]);

      final newer = tester.getTopLeft(find.textContaining('SR-2'));
      final older = tester.getTopLeft(find.textContaining('SR-1'));
      expect(newer.dy, lessThan(older.dy));
    });

    testWidgets('says what it does not cover', (tester) async {
      // Nobody may read the absence of a flood warning here as the absence of
      // a flood: there is no advisory feed behind this sheet.
      await _pumpSheet(tester, [_request(status: 'Pending')]);

      expect(
        find.text(
          'Updates about your own requests only. MDRRMO advisories are not sent here yet.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('is translated', (tester) async {
      await _pumpSheet(tester, const [], filipino: true);

      expect(find.text('Wala pang update'), findsOneWidget);
    });
  });
}
