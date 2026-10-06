// Home (calm redesign): the header shows the newest open request and counts
// the rest, all of them listed on Track; Announcements shows the newest two.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/dashboard_screen.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _FakeApi extends ApiService {}

const _resident = AppUser(
  id: '1',
  firstName: 'Juan',
  lastName: 'Dela Cruz',
  email: '',
  address: 'San Fabian',
);

ServiceRequest _request(int id, String status, {String? destination, int daysAgo = 0}) =>
    ServiceRequest.fromJson(<String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'status': status,
      'destination': destination,
      'service': {'service_name': 'Road Clearing $id', 'code': destination == null ? 'road-clearing' : 'ambulance-medical-response'},
      'created_at': DateTime.utc(2026, 9, 30).subtract(Duration(days: daysAgo)).toIso8601String(),
    });

BorrowRequest _loan(String item, BorrowStatus status, {int daysAgo = 0}) => BorrowRequest(
      id: 90,
      quantity: 1,
      status: status,
      equipmentName: item,
      createdAt: DateTime(2026, 9, 30).subtract(Duration(days: daysAgo)),
    );

InfoMaterial _material(int id, int day) => InfoMaterial(
      id: id,
      title: 'Advisory $id',
      fileType: 'pdf',
      sizeBytes: 1024,
      url: 'https://example.test/$id.pdf',
      publishedAt: DateTime(2026, 9, day),
    );

Future<AppState> _pump(
  WidgetTester tester, {
  List<ServiceRequest> requests = const [],
  List<BorrowRequest> loans = const [],
  List<InfoMaterial> materials = const [],
  VoidCallback? onOpenTrack,
  VoidCallback? onOpenLibrary,
  VoidCallback? onOpenBorrow,
}) async {
  tester.view.physicalSize = const Size(1080, 3600);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final state = AppState(_FakeApi());
  state.requests.addAll(requests);
  state.borrowRequests.addAll(loans);
  state.materials.addAll(materials);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ListenableBuilder(
        listenable: state,
        builder: (_, __) => HomeScreen(
          appState: state,
          user: _resident,
          onOpenTrack: onOpenTrack ?? () {},
          onOpenLibrary: onOpenLibrary ?? () {},
          onOpenProfile: () {},
          onOpenNotifications: () {},
          onOpenServices: () {},
          onOpenService: (_) {},
          onOpenBorrow: onOpenBorrow ?? () {},
        ),
      ),
    ),
  ));
  return state;
}

void main() {
  group('Your latest request', () {
    testWidgets('0 active: the panel is hidden', (tester) async {
      await _pump(tester, requests: [_request(1, 'Resolved')], loans: [_loan('Wheelchair', BorrowStatus.returned)]);

      expect(find.text('Your latest request'), findsNothing);
    });

    testWidgets('1 active: titled by destination, its status, nothing more in progress', (tester) async {
      var tracked = 0;
      await _pump(
        tester,
        onOpenTrack: () => tracked++,
        requests: [_request(1, 'Responding', destination: 'Echague District Hospital')],
      );

      expect(find.text('Your latest request'), findsOneWidget);
      expect(find.text('Ambulance to Echague District Hospital'), findsOneWidget);
      expect(find.text('Responding'), findsOneWidget);
      expect(find.text('Open in Track'), findsOneWidget);
      expect(find.textContaining('more in progress'), findsNothing);

      await tester.tap(find.text('Ambulance to Echague District Hospital'));
      expect(tracked, 1);
    });

    testWidgets('2 active: the newest one, a loan, opens Borrow and counts the other', (tester) async {
      var borrowed = 0;
      await _pump(
        tester,
        onOpenBorrow: () => borrowed++,
        requests: [_request(1, 'Pending', daysAgo: 2)],
        loans: [_loan('Wheelchair', BorrowStatus.pending)],
      );

      expect(find.text('Wheelchair × 1'), findsOneWidget);
      expect(find.text('Road Clearing 1'), findsNothing);
      expect(find.text('Under review'), findsOneWidget);
      expect(find.text('Open in Borrow'), findsOneWidget);
      expect(find.textContaining('1 more in progress'), findsOneWidget);

      await tester.tap(find.text('Wheelchair × 1'));
      expect(borrowed, 1);
    });

    testWidgets('5 active: the newest, with the other 4 counted', (tester) async {
      await _pump(tester, requests: [for (var i = 1; i <= 5; i++) _request(i, 'Pending', daysAgo: i)]);

      expect(find.text('Road Clearing 1'), findsOneWidget);
      for (final hidden in ['Road Clearing 2', 'Road Clearing 3', 'Road Clearing 4', 'Road Clearing 5']) {
        expect(find.text(hidden), findsNothing);
      }
      expect(find.textContaining('4 more in progress'), findsOneWidget);
    });
  });

  group('Track: Borrowed items', () {
    Future<void> pumpTrack(WidgetTester tester, AppState state, {VoidCallback? onOpenMyLoans}) async {
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: TrackScreen(
            appState: state,
            onOpenNotifications: () {},
            onOpenProfile: () {},
            onOpenMyLoans: onOpenMyLoans ?? () {},
            onBrowseServices: () {},
          ),
        ),
      ));
    }

    testWidgets('lists open loans newest first, opens My requests, and matches Home\'s count', (tester) async {
      var openedLoans = 0;
      final state = await _pump(
        tester,
        requests: [
          for (var i = 1; i <= 3; i++) _request(i, 'Pending', daysAgo: i),
          _request(4, 'Resolved', daysAgo: 9),
        ],
        loans: [
          _loan('Wheelchair', BorrowStatus.released, daysAgo: 3),
          _loan('Crutches', BorrowStatus.pending, daysAgo: 1),
          _loan('Stretcher', BorrowStatus.returned),
        ],
      );
      final homeMore = int.parse(
        RegExp(r'(\d+) more in progress').firstMatch(tester.widget<Text>(find.textContaining('more in progress')).data!)!.group(1)!,
      );
      final homeCount = homeMore + 1;

      await pumpTrack(tester, state, onOpenMyLoans: () => openedLoans++);

      expect(find.text('Borrowed items  2'), findsOneWidget);
      expect(find.text('Stretcher × 1'), findsNothing);
      expect(
        tester.getTopLeft(find.text('Crutches × 1')).dy,
        lessThan(tester.getTopLeft(find.text('Wheelchair × 1')).dy),
      );

      final openOnTrack = [
        for (final title in ['Road Clearing 1', 'Road Clearing 2', 'Road Clearing 3', 'Crutches × 1', 'Wheelchair × 1'])
          ...find.text(title).evaluate(),
      ].length;
      expect(homeCount, 5);
      expect(openOnTrack, homeCount);

      await tester.ensureVisible(find.text('Crutches × 1'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Crutches × 1'));
      expect(openedLoans, 1);
    });

    testWidgets('hidden with no open loans', (tester) async {
      final state = await _pump(tester, requests: [_request(1, 'Pending')], loans: [_loan('Wheelchair', BorrowStatus.returned)]);
      await pumpTrack(tester, state);

      expect(find.textContaining('Borrowed items'), findsNothing);
    });
  });

  group('Announcements', () {
    testWidgets('0: one muted line', (tester) async {
      await _pump(tester);

      expect(find.text('MDRRMO has not published anything yet.'), findsOneWidget);
    });

    testWidgets('4: only the newest 2, and See all opens the Info center', (tester) async {
      var opened = 0;
      await _pump(tester, onOpenLibrary: () => opened++, materials: [
        _material(1, 1),
        _material(2, 20),
        _material(3, 10),
        _material(4, 25),
      ]);

      expect(find.text('Advisory 4'), findsOneWidget);
      expect(find.text('Advisory 2'), findsOneWidget);
      expect(find.text('Advisory 3'), findsNothing);
      expect(find.text('Advisory 1'), findsNothing);

      await tester.tap(find.text('See all'));
      expect(opened, 1);
    });
  });

  testWidgets('fits a 320px phone in Filipino without overflow', (tester) async {
    final state = await _pump(
      tester,
      requests: [for (var i = 1; i <= 5; i++) _request(i, 'Responding', destination: 'Echague District Hospital, Brgy. San Fabian', daysAgo: i)],
      materials: [_material(1, 1), _material(2, 2)],
    );
    tester.view.physicalSize = const Size(320, 2400);
    tester.view.devicePixelRatio = 1;
    state.setLanguage(AppLanguage.filipino);
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('Ang iyong pinakabagong kahilingan'), findsOneWidget);
  });

  testWidgets('first launch stores now and shows no dot for existing history', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final state = await _pump(tester, requests: [_request(1, 'Pending')]);

    await tester.runAsync(state.loadNotificationsSeen);
    await tester.pump();

    expect(find.byKey(const ValueKey('header-button-dot')), findsNothing);
    final stored = await tester.runAsync(() async => (await SharedPreferences.getInstance()).getString('serbis.notifications.seen_at.v1'));
    expect(stored, isNotNull);
  });

  testWidgets('the bell shows a dot for an update since the sheet was opened, until it is opened again', (tester) async {
    SharedPreferences.setMockInitialValues({'serbis.notifications.seen_at.v1': '2026-01-01T00:00:00.000'});
    final state = await _pump(tester, requests: [_request(1, 'Pending')]);
    final dot = find.byKey(const ValueKey('header-button-dot'));

    expect(dot, findsNothing);
    await tester.runAsync(state.loadNotificationsSeen);
    await tester.pump();
    expect(dot, findsOneWidget);

    state.markNotificationsSeen();
    await tester.pump();
    expect(dot, findsNothing);
  });
}
