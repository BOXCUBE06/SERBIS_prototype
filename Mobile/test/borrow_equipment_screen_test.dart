// `borrow_equipment_screen.dart` was 1/217 lines — 0.5%, the least-covered file
// in the app by a wide margin, and the only screen a resident can use to create
// something MDRRMO has to act on that had no widget test at all. The store half
// is covered by borrow_store_test.dart; this is the screen half.
//
// The branches worth pinning, in the order a resident meets them:
//
//  * the catalogue can fail, and an empty list must say so with a way back
//    rather than looking like an office that lends nothing;
//  * an item with no stock must not offer a Borrow button — the screen decides
//    this, not the sheet, so a resident cannot open a picker that can only fail;
//  * the quantity stepper must clamp at 1 and at what is actually in stock,
//    because the server's stock check is a 422 and the client should never
//    knowingly send one;
//  * a submit that fails must leave the sheet open with the reason on it, since
//    closing it would read as success;
//  * a submit that succeeds must close the sheet, switch to My Requests, and
//    say so — the request is invisible on the Available tab it was filed from.
//
// AppState reaches SharedPreferences through BorrowCache, so the mock values
// are installed in setUp. Without them the cache call hangs rather than
// throwing, and a test that sits until timeout here means that, not slowness.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/borrow_equipment_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _FakeApi extends ApiService {
  _FakeApi({
    this.equipmentRows,
    this.borrowRows,
    this.equipmentError,
    this.submitError,
  });

  List<Map<String, dynamic>>? equipmentRows;
  List<Map<String, dynamic>>? borrowRows;
  Object? equipmentError;
  Object? submitError;

  int submitCalls = 0;
  int getEquipmentsCalls = 0;
  int? lastQuantity;
  String? lastPurpose;

  /// Holds the catalogue fetch open so the loading frame is observable rather
  /// than a race the fake usually wins.
  Completer<void>? equipmentGate;

  @override
  Future<List<Map<String, dynamic>>> getEquipments() async {
    getEquipmentsCalls++;
    if (equipmentGate != null) await equipmentGate!.future;
    if (equipmentError != null) throw equipmentError!;
    return equipmentRows ?? <Map<String, dynamic>>[];
  }

  @override
  Future<List<Map<String, dynamic>>> getBorrowings() async {
    return borrowRows ?? <Map<String, dynamic>>[];
  }

  @override
  Future<Map<String, dynamic>> submitBorrowRequest({
    required int equipmentId,
    required int quantity,
    required String purpose,
  }) async {
    submitCalls++;
    lastQuantity = quantity;
    lastPurpose = purpose;
    if (submitError != null) throw submitError!;
    return <String, dynamic>{
      'borrow_id': 77,
      'equipment_id': equipmentId,
      'quantity': quantity,
      'purpose': purpose,
      'status': 'Pending',
      'created_at': DateTime.now().toIso8601String(),
    };
  }
}

Map<String, dynamic> _equipmentRow(int id, String name, int qty) => <String, dynamic>{
      'equipment_id': id,
      'item_name': name,
      'available_quantity': qty,
    };

Widget _host(AppState state) => MaterialApp(
      home: BorrowEquipmentScreen(appState: state),
    );

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
  });

  group('the Available tab', () {
    testWidgets('shows a spinner while the catalogue is still loading', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)])
        ..equipmentGate = Completer<void>();
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Wheelchair'), findsNothing);

      api.equipmentGate!.complete();
      await tester.pumpAndSettle();

      expect(find.text('Wheelchair'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('a failed catalogue offers a retry that refetches', (tester) async {
      final api = _FakeApi(equipmentError: const ApiException('Network unreachable'));
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      expect(find.text('Network unreachable'), findsOneWidget);
      expect(api.getEquipmentsCalls, 1);

      // The retry must actually go back to the server. Clear the error first so
      // a successful second attempt is distinguishable from a no-op.
      api.equipmentError = null;
      api.equipmentRows = [_equipmentRow(1, 'Wheelchair', 2)];
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(api.getEquipmentsCalls, 2);
      expect(find.text('Wheelchair'), findsOneWidget);
    });

    testWidgets('an empty catalogue says so instead of rendering nothing', (tester) async {
      await tester.pumpWidget(_host(AppState(_FakeApi(equipmentRows: []))));
      await tester.pumpAndSettle();

      expect(find.text('Nothing available right now'), findsOneWidget);
    });

    testWidgets('an out-of-stock item cannot be borrowed', (tester) async {
      final api = _FakeApi(equipmentRows: [
        _equipmentRow(1, 'Wheelchair', 2),
        _equipmentRow(2, 'Oxygen Tank', 0),
      ]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      expect(find.text('None available right now'), findsOneWidget);
      expect(find.text('2 available'), findsOneWidget);

      // Both cards draw a Borrow button; only the in-stock one is enabled.
      final buttons = tester
          .widgetList<OutlinedButton>(find.byType(OutlinedButton))
          .toList();
      expect(buttons, hasLength(2));
      expect(buttons.where((b) => b.onPressed == null), hasLength(1));
    });
  });

  group('the borrow sheet', () {
    Future<AppState> openSheet(WidgetTester tester, _FakeApi api) async {
      final state = AppState(api);
      await tester.pumpWidget(_host(state));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Borrow').first);
      await tester.pumpAndSettle();
      return state;
    }

    /// Every submit path below has to clear the required purpose field first,
    /// or it never reaches the API at all.
    Future<void> fillPurpose(WidgetTester tester, [String text = 'Barangay flood drill']) async {
      await tester.enterText(find.byType(TextField), text);
      await tester.pump();
    }

    testWidgets('an empty purpose is refused before anything is sent', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.text('Request this item'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 0, reason: 'nothing to review, so nothing to file');
      expect(find.text('Tell MDRRMO what you need this for.'), findsOneWidget);
      expect(find.text('Request this item'), findsOneWidget);
    });

    testWidgets('whitespace alone does not count as a purpose', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await fillPurpose(tester, '   ');
      await tester.tap(find.text('Request this item'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 0);
      expect(find.text('Tell MDRRMO what you need this for.'), findsOneWidget);
    });

    testWidgets('the error clears once there is something to send', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.text('Request this item'));
      await tester.pumpAndSettle();
      expect(find.text('Tell MDRRMO what you need this for.'), findsOneWidget);

      await fillPurpose(tester);

      expect(find.text('Tell MDRRMO what you need this for.'), findsNothing);
    });

    testWidgets('the stepper clamps at 1 and at what is in stock', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      expect(find.text('2 available to borrow'), findsOneWidget);
      expect(find.text('1'), findsOneWidget);

      // Below 1 is refused.
      await tester.tap(find.byIcon(Icons.remove_rounded));
      await tester.pump();
      expect(find.text('1'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump();
      expect(find.text('2'), findsOneWidget);

      // Past the available quantity is refused too, so the client never
      // knowingly sends a request the stock check will reject.
      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump();
      expect(find.text('2'), findsOneWidget);
      expect(find.text('3'), findsNothing);
    });

    testWidgets('a rejected submit keeps the sheet open and shows why', (tester) async {
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)],
        submitError: const ApiException('Only 1 of this item are available to borrow.'),
      );
      await openSheet(tester, api);

      await fillPurpose(tester);
      await tester.tap(find.text('Request this item'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 1);
      expect(find.text('Only 1 of this item are available to borrow.'), findsOneWidget);
      // Still on the sheet — closing it would read as a filed request.
      expect(find.text('Request this item'), findsOneWidget);
      expect(find.text('2 available to borrow'), findsOneWidget);
    });

    testWidgets('a successful submit closes the sheet, switches tabs and says so',
        (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump();
      await fillPurpose(tester, 'Evacuation centre setup');
      await tester.tap(find.text('Request this item'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 1);
      expect(api.lastQuantity, 2, reason: 'the stepper value must reach the server');
      expect(api.lastPurpose, 'Evacuation centre setup',
          reason: 'what MDRRMO decides on must reach the server too');

      // Sheet gone.
      expect(find.text('Request this item'), findsNothing);
      // Told the resident, by name, what happens next.
      expect(
        find.text('Request filed for Wheelchair. MDRRMO will review it.'),
        findsOneWidget,
      );
      // And moved them to the tab the new request is actually on — it is
      // invisible on the Available tab it was filed from.
      expect(find.text('My Requests (1)'), findsOneWidget);
      expect(find.textContaining('Wheelchair × 2'), findsOneWidget);
    });
  });

  group('the My Requests tab', () {
    testWidgets('says nothing is filed yet rather than rendering an empty list',
        (tester) async {
      await tester.pumpWidget(_host(AppState(_FakeApi(equipmentRows: []))));
      await tester.pumpAndSettle();

      await tester.tap(find.text('My Requests (0)'));
      await tester.pumpAndSettle();

      expect(find.text('No borrow requests yet'), findsOneWidget);
    });

    testWidgets('lists already-filed requests with their status', (tester) async {
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)],
        borrowRows: [
          <String, dynamic>{
            'borrow_id': 5,
            'equipment_id': 1,
            'quantity': 3,
            'status': 'Approved',
            'created_at': DateTime.now().toIso8601String(),
            'equipment': <String, dynamic>{'item_name': 'Megaphone'},
          },
        ],
      );
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      expect(find.text('My Requests (1)'), findsOneWidget);
      await tester.tap(find.text('My Requests (1)'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Megaphone × 3'), findsOneWidget);
      expect(find.text('No borrow requests yet'), findsNothing);
    });
  });
}
