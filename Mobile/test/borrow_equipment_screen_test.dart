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
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/borrow_equipment_screen.dart';
import 'package:serbis/state/account_store.dart';
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

  /// The last body's item source and the four fields added for #1 and #9.
  /// Held as a raw map as well, so a test can assert a key is *absent* rather
  /// than only that its value was null.
  Map<String, dynamic> lastBody = <String, dynamic>{};

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

  /// Bytes per stage, and the stages actually asked for. A stage missing from
  /// the map answers null, which is what the real call does for a loan nobody
  /// photographed.
  Map<String, List<int>> photos = <String, List<int>>{};
  List<String> photoStagesFetched = <String>[];

  @override
  Future<List<int>?> fetchHandoverPhoto(int borrowId, String stage) async {
    photoStagesFetched.add(stage);
    return photos[stage];
  }

  @override
  Future<Map<String, dynamic>> submitBorrowRequest({
    int? equipmentId,
    String? otherEquipmentText,
    required int quantity,
    required String purpose,
    String fulfillmentMethod = 'Pickup',
    String? deliveryAddress,
  }) async {
    submitCalls++;
    lastQuantity = quantity;
    lastPurpose = purpose;
    // Mirrors ApiService's own body construction, so a test asserting on a
    // missing key is asserting about what would actually go over the wire.
    lastBody = <String, dynamic>{
      if (equipmentId != null)
        'equipment_id': equipmentId
      else
        'other_equipment_text': otherEquipmentText,
      'quantity': quantity,
      'purpose': purpose,
      'fulfillment_method': fulfillmentMethod,
      if (fulfillmentMethod == 'Delivery') 'delivery_address': deliveryAddress,
    };
    if (submitError != null) throw submitError!;
    return <String, dynamic>{
      'borrow_id': 77,
      'equipment_id': equipmentId,
      'other_equipment_text': otherEquipmentText,
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

const _resident = AppUser(
  id: '1',
  firstName: 'Maria',
  lastName: 'Santos',
  email: 'maria@example.com',
  address: 'San Fabian',
);

Widget _host(AppState state) => MaterialApp(
      home: BorrowEquipmentScreen(appState: state, user: _resident),
    );

/// Text fields inside the open borrow sheet, top to bottom.
Finder _sheetFields() => find.descendant(of: find.byType(BottomSheet), matching: find.byType(TextField));

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

      expect(find.text('Unavailable'), findsOneWidget);
      expect(find.text('2 available'), findsOneWidget);

      // Both cards draw a Borrow button; only the in-stock one is enabled.
      final buttons = tester
          .widgetList<TextButton>(find.widgetWithText(TextButton, 'Borrow'))
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
      await tester.enterText(_sheetFields().last, text);
      await tester.pump();
    }

    testWidgets('an empty purpose is refused before anything is sent', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.text('Send request'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 0, reason: 'nothing to review, so nothing to file');
      expect(find.text('Tell MDRRMO what you need this for.'), findsOneWidget);
      expect(find.text('Send request'), findsOneWidget);
    });

    testWidgets('whitespace alone does not count as a purpose', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await fillPurpose(tester, '   ');
      await tester.tap(find.text('Send request'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 0);
      expect(find.text('Tell MDRRMO what you need this for.'), findsOneWidget);
    });

    testWidgets('the error clears once there is something to send', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.text('Send request'));
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
      await tester.tap(find.byTooltip('Decrease quantity'));
      await tester.pump();
      expect(find.text('1'), findsOneWidget);

      await tester.tap(find.byTooltip('Increase quantity'));
      await tester.pump();
      expect(find.text('2'), findsOneWidget);

      // Past the available quantity is refused too, so the client never
      // knowingly sends a request the stock check will reject.
      await tester.tap(find.byTooltip('Increase quantity'));
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
      await tester.tap(find.text('Send request'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 1);
      expect(find.text('Only 1 of this item are available to borrow.'), findsOneWidget);
      // Still on the sheet — closing it would read as a filed request.
      expect(find.text('Send request'), findsOneWidget);
      expect(find.text('2 available to borrow'), findsOneWidget);
    });

    testWidgets('a successful submit closes the sheet, switches tabs and says so',
        (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await openSheet(tester, api);

      await tester.tap(find.byTooltip('Increase quantity'));
      await tester.pump();
      await fillPurpose(tester, 'Evacuation centre setup');
      await tester.tap(find.text('Send request'));
      await tester.pumpAndSettle();

      expect(api.submitCalls, 1);
      expect(api.lastQuantity, 2, reason: 'the stepper value must reach the server');
      expect(api.lastPurpose, 'Evacuation centre setup',
          reason: 'what MDRRMO decides on must reach the server too');

      // Sheet gone.
      expect(find.text('Send request'), findsNothing);
      // Told the resident, by name, what happens next.
      expect(
        find.text('Request filed for Wheelchair. MDRRMO will review it.'),
        findsOneWidget,
      );
      // And moved them to the tab the new request is actually on — it is
      // invisible on the Available tab it was filed from.
      expect(find.text('My requests'), findsOneWidget);
      expect(find.text('Wheelchair'), findsWidgets);
      expect(find.text('Quantity: 2'), findsOneWidget);
    });
  });

  group('the My Requests tab', () {
    testWidgets('says nothing is filed yet rather than rendering an empty list',
        (tester) async {
      await tester.pumpWidget(_host(AppState(_FakeApi(equipmentRows: []))));
      await tester.pumpAndSettle();

      await tester.tap(find.text('My requests'));
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

      await tester.tap(find.text('My requests'));
      await tester.pumpAndSettle();

      expect(find.text('Megaphone'), findsOneWidget);
      expect(find.text('Quantity: 3'), findsOneWidget);
      expect(find.text('No borrow requests yet'), findsNothing);
    });

    Map<String, dynamic> row(int id, String status, {String? method, String? reason, int equipmentId = 1}) => {
          'borrow_id': id,
          'equipment_id': equipmentId,
          'quantity': 1,
          'status': status,
          'fulfillment_method': method,
          'denial_reason': reason,
          'created_at': DateTime(2026, 9, 30, 1, 11).toIso8601String(),
          'released_at': status == 'Released' ? DateTime(2026, 10, 1, 9).toIso8601String() : null,
          'updated_at': DateTime(2026, 10, 1, 14, 5).toIso8601String(),
          'equipment': <String, dynamic>{'item_name': 'Item $id'},
        };

    Future<void> openMine(WidgetTester tester, List<Map<String, dynamic>> rows) async {
      tester.view.physicalSize = const Size(1080, 9000);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(_host(AppState(_FakeApi(
        equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)],
        borrowRows: rows,
      ))));
      await tester.pumpAndSettle();
      await tester.tap(find.text('My requests'));
      await tester.pumpAndSettle();
    }

    testWidgets('splits open requests from past ones', (tester) async {
      await openMine(tester, [row(1, 'Pending'), row(2, 'Returned'), row(3, 'Cancelled')]);

      expect(find.text('In progress'), findsOneWidget);
      expect(find.text('Past requests'), findsOneWidget);
      expect(
        tester.getTopLeft(find.text('Item 1')).dy < tester.getTopLeft(find.text('Past requests')).dy,
        isTrue,
        reason: 'the pending request sits above the Past heading',
      );
    });

    testWidgets('an approved pickup shows the status box, steps and both actions', (tester) async {
      await openMine(tester, [row(1, 'Approved', method: 'Pickup')]);

      expect(find.text('Approved — ready to pick up'), findsOneWidget);
      expect(find.text('Request sent'), findsOneWidget);
      expect(find.text('Wed, Sep 30, 1:11 AM'), findsOneWidget, reason: 'done steps carry their time');
      expect(find.text('Ready to pick up'), findsOneWidget);
      expect(find.text('Call MDRRMO'), findsOneWidget);
      expect(find.text('Cancel request'), findsOneWidget);

      await tester.tap(find.text('Cancel request'));
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsOneWidget, reason: 'cancelling asks first');
    });

    testWidgets('a released delivery reads Delivered and can no longer be cancelled', (tester) async {
      await openMine(tester, [row(1, 'Released', method: 'Delivery')]);

      expect(find.text('Delivered to you'), findsOneWidget);
      expect(find.text('Delivered'), findsOneWidget);
      expect(find.text("Return it to MDRRMO when you're done."), findsOneWidget);
      expect(find.text('Cancel request'), findsNothing);
    });

    testWidgets('a denied request shows MDRRMO\'s reason and no steps', (tester) async {
      await openMine(tester, [row(1, 'Denied', reason: 'Out of stock')]);

      expect(find.text('Not approved'), findsOneWidget);
      expect(find.text("MDRRMO's reason: Out of stock"), findsOneWidget);
      expect(find.text('Request sent'), findsNothing);
      expect(find.text('Cancel request'), findsNothing);
    });

    testWidgets('a cancelled request offers Borrow again only while the item exists', (tester) async {
      await openMine(tester, [row(1, 'Cancelled'), row(2, 'Cancelled', equipmentId: 99)]);

      expect(find.text('Cancelled'), findsNWidgets(2));
      expect(find.text('You cancelled this on Thu, Oct 1, 2:05 PM.'), findsNWidgets(2));
      expect(find.text('Borrow again'), findsOneWidget, reason: 'item 99 is not in the catalogue');

      await tester.tap(find.text('Borrow again'));
      await tester.pumpAndSettle();
      expect(find.text('Send request'), findsOneWidget, reason: "opens that item's borrow sheet");
    });
  });

  // The handover photographs. Staff take them at the counter and the endpoint
  // has been owner-scoped since it was written — the resident could always
  // read their own back, this app simply never asked. Display only: uploading
  // is behind `is.admin` and nothing here can add or replace one.
  group('handover photos', () {
    Map<String, dynamic> borrowRow({
      String status = 'Returned',
      bool hasRelease = false,
      bool hasReturn = false,
    }) =>
        <String, dynamic>{
          'borrow_id': 5,
          'equipment_id': 1,
          'quantity': 1,
          'status': status,
          'created_at': DateTime.now().toIso8601String(),
          'equipment': <String, dynamic>{'item_name': 'Megaphone'},
          'has_release_photo': hasRelease,
          'has_return_photo': hasReturn,
        };

    Future<void> openMine(WidgetTester tester, _FakeApi api) async {
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('My requests'));
      await tester.pumpAndSettle();
    }

    testWidgets('a loan nobody photographed draws no photo section', (tester) async {
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Megaphone', 2)],
        borrowRows: [borrowRow()],
      );
      await openMine(tester, api);

      expect(find.text('Handover photos'), findsNothing);
      // Nothing to fetch, so nothing is fetched: the bytes are heavier than
      // the whole list and must never be pulled speculatively.
      expect(api.photoStagesFetched, isEmpty);
    });

    testWidgets('both stages are shown and each is fetched once', (tester) async {
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Megaphone', 2)],
        borrowRows: [borrowRow(hasRelease: true, hasReturn: true)],
      )..photos = <String, List<int>>{'release': _onePixelPng, 'return': _onePixelPng};
      await openMine(tester, api);

      expect(find.text('Handover photos'), findsOneWidget);
      expect(find.text('Released'), findsOneWidget);
      expect(find.text('Returned'), findsWidgets);
      expect(api.photoStagesFetched, <String>['release', 'return']);
      expect(find.byType(Image), findsNWidgets(2));
    });

    testWidgets('a release-only loan asks for that stage alone', (tester) async {
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Megaphone', 2)],
        borrowRows: [borrowRow(status: 'Released', hasRelease: true)],
      )..photos = <String, List<int>>{'release': _onePixelPng};
      await openMine(tester, api);

      expect(find.text('Released'), findsOneWidget);
      expect(api.photoStagesFetched, <String>['release']);
    });

    testWidgets('a photo the server will not serve leaves a placeholder, not a crash',
        (tester) async {
      // fetchHandoverPhoto answers null on a 404 or a dead connection alike.
      // The tile has to survive that inside a list item.
      final api = _FakeApi(
        equipmentRows: [_equipmentRow(1, 'Megaphone', 2)],
        borrowRows: [borrowRow(hasRelease: true)],
      );
      await openMine(tester, api);

      expect(find.byIcon(Icons.image_not_supported_outlined), findsOneWidget);
      expect(find.byType(Image), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  // #1, #9 and #10. Each of the three added a field the resident could not
  // reach before, and two of the three are conditional — the branch worth
  // pinning is not that the field works but that it is absent from the body
  // when the toggle is on its default, since the server drops those columns
  // and a stray value would be a delivery nobody makes.
  group('the borrow sheet', () {
    /// The sheet is taller than the test viewport once both toggles are on it,
    /// so a bare tap() silently misses and the assertion after it fails for
    /// the wrong reason. Scroll the target into view first, every time.
    Future<void> tapVisible(WidgetTester tester, Finder target) async {
      await tester.ensureVisible(target);
      await tester.pumpAndSettle();
      await tester.tap(target);
      await tester.pumpAndSettle();
    }

    Future<void> openSheet(WidgetTester tester, {bool other = false}) async {
      await tapVisible(tester, find.text(other ? 'Need something else?' : 'Borrow').first);
    }

    Future<void> submit(WidgetTester tester) async {
      await tapVisible(tester, find.text('Send request'));
    }

    Future<void> fill(WidgetTester tester, Finder field, String text) async {
      await tester.ensureVisible(field);
      await tester.pumpAndSettle();
      await tester.enterText(field, text);
      await tester.pumpAndSettle();
    }

    testWidgets('a plain request is a Pickup by a Resident for a catalogued item', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);
      await fill(tester, _sheetFields().first, 'Flood drill');
      await submit(tester);

      expect(api.lastBody['equipment_id'], 1);
      expect(api.lastBody.containsKey('other_equipment_text'), isFalse);
      expect(api.lastBody['fulfillment_method'], 'Pickup');
      // The borrower kind is the server's call now, from the account.
      // The two conditional fields are the point: defaults must send nothing.
      expect(api.lastBody.containsKey('delivery_address'), isFalse);
      expect(api.lastBody.containsKey('borrower_type'), isFalse);
    });

    testWidgets('the delivery address appears only for Delivery', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);
      expect(find.text('Deliver to'), findsNothing);

      await tapVisible(tester, find.text('Delivery'));
      expect(find.text('Deliver to'), findsOneWidget);

      await tapVisible(tester, find.text('Pickup'));
      expect(find.text('Deliver to'), findsNothing);
    });

    testWidgets(
        'choosing Delivery prefills the saved address',
        (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);
      expect(find.widgetWithText(TextField, _resident.fullAddress), findsNothing);

      await tapVisible(tester, find.text('Delivery'));

      expect(find.widgetWithText(TextField, _resident.fullAddress), findsOneWidget);
    });

    testWidgets('a Delivery with no address is refused before it is sent', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);
      await fill(tester, _sheetFields().first, 'Flood drill');
      await tapVisible(tester, find.text('Delivery'));
      await fill(tester, _sheetFields().last, '');
      await submit(tester);

      expect(find.text('Where should MDRRMO deliver it?'), findsOneWidget);
      expect(api.submitCalls, 0);

      await fill(tester, _sheetFields().last, '12 Mabini St, San Fabian');
      await submit(tester);

      expect(api.submitCalls, 1);
      expect(api.lastBody['fulfillment_method'], 'Delivery');
      expect(api.lastBody['delivery_address'], '12 Mabini St, San Fabian');
    });

    testWidgets('an address typed and then switched away from is not sent', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);
      await fill(tester, _sheetFields().first, 'Flood drill');
      await tapVisible(tester, find.text('Delivery'));
      await fill(tester, _sheetFields().last, '12 Mabini St');
      await tapVisible(tester, find.text('Pickup'));
      await submit(tester);

      expect(api.lastBody['fulfillment_method'], 'Pickup');
      expect(api.lastBody.containsKey('delivery_address'), isFalse);
    });

    testWidgets('an uncatalogued request names the item and sends no equipment_id', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester, other: true);
      expect(find.text('What do you need?'), findsOneWidget);

      await fill(tester, _sheetFields().first, 'Portable generator');
      await fill(tester, _sheetFields().at(1), 'Evacuation centre power');
      await submit(tester);

      expect(api.lastBody['other_equipment_text'], 'Portable generator');
      // Never both and never neither: the table's CHECK constraint answers a
      // body carrying the pair with a 500, not a 422.
      expect(api.lastBody.containsKey('equipment_id'), isFalse);
    });

    testWidgets('an uncatalogued request with no item name is refused before it is sent', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester, other: true);
      await fill(tester, _sheetFields().at(1), 'Evacuation centre power');
      await submit(tester);

      expect(find.text('Name the item you need.'), findsOneWidget);
      expect(api.submitCalls, 0);
    });

    testWidgets('a catalogued request never offers the free-text item field', (tester) async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(1, 'Wheelchair', 2)]);
      await tester.pumpWidget(_host(AppState(api)));
      await tester.pumpAndSettle();

      await openSheet(tester);

      expect(find.text('What do you need?'), findsNothing);
      expect(find.text('Wheelchair'), findsWidgets);
    });

    testWidgets('the empty catalogue still offers the free-text path', (tester) async {
      await tester.pumpWidget(_host(AppState(_FakeApi(equipmentRows: []))));
      await tester.pumpAndSettle();

      expect(find.text('Nothing available right now'), findsOneWidget);
      expect(find.text('Need something else?'), findsOneWidget);
    });
  });
}

/// A real 1x1 PNG. Image.memory decodes whatever it is handed, and a tile
/// asserting on find.byType(Image) is only meaningful if the bytes are ones a
/// codec would accept.
final List<int> _onePixelPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
);
