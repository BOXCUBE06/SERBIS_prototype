// Track (redesigned): In progress, Borrowed items, Past in that order; empty
// sections hidden; Past capped at the latest 5; one empty state.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {}

ServiceRequest _request(int id, String status, {int daysAgo = 0}) => ServiceRequest.fromJson(<String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'status': status,
      'service': {'service_name': 'Road Clearing $id', 'code': 'road-clearing'},
      'created_at': DateTime.utc(2026, 9, 30).subtract(Duration(days: daysAgo)).toIso8601String(),
    });

BorrowRequest _loan(String item, BorrowStatus status) => BorrowRequest(
      id: 90,
      quantity: 1,
      status: status,
      equipmentName: item,
      createdAt: DateTime(2026, 9, 30),
    );

Future<void> _pump(
  WidgetTester tester, {
  List<ServiceRequest> requests = const [],
  List<BorrowRequest> loans = const [],
  VoidCallback? onBrowseServices,
}) async {
  tester.view.physicalSize = const Size(1080, 6000);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final state = AppState(_FakeApi());
  state.requests.addAll(requests);
  state.borrowRequests.addAll(loans);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: TrackScreen(
        appState: state,
        onOpenNotifications: () {},
        onOpenProfile: () {},
        onOpenMyLoans: () {},
        onBrowseServices: onBrowseServices ?? () {},
      ),
    ),
  ));
}

void main() {
  testWidgets('sections run In progress, Borrowed items, Past', (tester) async {
    await _pump(
      tester,
      requests: [_request(1, 'Pending'), _request(2, 'Resolved', daysAgo: 3)],
      loans: [_loan('Wheelchair', BorrowStatus.pending)],
    );

    double y(String text) => tester.getTopLeft(find.text(text)).dy;
    expect(find.text('Track'), findsOneWidget);
    expect(find.text('Your requests and borrowed items'), findsOneWidget);
    expect(y('In progress'), lessThan(y('Borrowed items')));
    expect(y('Borrowed items'), lessThan(y('Past')));
  });

  testWidgets('an empty section is hidden', (tester) async {
    await _pump(tester, requests: [_request(1, 'Pending')]);

    expect(find.text('In progress'), findsOneWidget);
    expect(find.text('Borrowed items'), findsNothing);
    expect(find.text('Past'), findsNothing);
  });

  testWidgets('Past shows the latest 5, then Show older requests loads the rest', (tester) async {
    await _pump(tester, requests: [for (var i = 1; i <= 7; i++) _request(i, 'Resolved', daysAgo: i)]);

    for (var i = 1; i <= 5; i++) {
      expect(find.text('Road Clearing $i'), findsOneWidget);
    }
    expect(find.text('Road Clearing 6'), findsNothing);
    expect(find.text('Road Clearing 7'), findsNothing);

    await tester.ensureVisible(find.text('Show older requests'));
    await tester.tap(find.text('Show older requests'));
    await tester.pumpAndSettle();

    expect(find.text('Road Clearing 6'), findsOneWidget);
    expect(find.text('Road Clearing 7'), findsOneWidget);
    expect(find.text('Show older requests'), findsNothing);
  });

  testWidgets('no button when Past fits in 5', (tester) async {
    await _pump(tester, requests: [for (var i = 1; i <= 5; i++) _request(i, 'Resolved', daysAgo: i)]);

    expect(find.text('Show older requests'), findsNothing);
  });

  testWidgets('nothing at all: one muted line and Browse services opens Services', (tester) async {
    var browsed = 0;
    await _pump(tester, onBrowseServices: () => browsed++);

    expect(find.text('You have no requests yet.'), findsOneWidget);
    expect(find.text('In progress'), findsNothing);
    expect(find.text('Borrowed items'), findsNothing);
    expect(find.text('Past'), findsNothing);

    await tester.tap(find.text('Browse services'));
    expect(browsed, 1);
  });

  testWidgets('a cancellable open request offers Cancel and Call MDRRMO', (tester) async {
    await _pump(tester, requests: [
      ServiceRequest.fromJson(<String, dynamic>{
        'request_id': 5,
        'status': 'Pending',
        'can_cancel': true,
        'service': {'service_name': 'Road Clearing 5', 'code': 'road-clearing'},
      }),
    ]);

    expect(find.text('Cancel request'), findsOneWidget);
    expect(find.text('Call MDRRMO'), findsOneWidget);
    expect(find.text('Waiting for MDRRMO'), findsOneWidget);
  });
}
