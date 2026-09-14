// Covers the store half of equipment borrowing: submit-with-rollback,
// refetch guards, and cache hydration. Mirrors requests_refresh_test.dart's
// shape for ServiceRequest — see there for the reasoning behind each guard.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

class _FakeApi extends ApiService {
  _FakeApi({this.equipmentRows, this.borrowRows, this.submitResult, this.submitError, this.loadError});

  List<Map<String, dynamic>>? equipmentRows;
  List<Map<String, dynamic>>? borrowRows;
  Map<String, dynamic>? submitResult;
  Object? submitError;
  Object? loadError;
  Object? cancelError;
  int getBorrowingsCalls = 0;
  int submitCalls = 0;
  int cancelCalls = 0;
  int? lastCancelledId;
  String? lastPurpose;

  @override
  Future<void> cancelBorrowRequest(int borrowId) async {
    cancelCalls++;
    lastCancelledId = borrowId;
    if (cancelError != null) throw cancelError!;
  }

  @override
  Future<List<Map<String, dynamic>>> getEquipments() async {
    return equipmentRows ?? <Map<String, dynamic>>[];
  }

  @override
  Future<List<Map<String, dynamic>>> getBorrowings() async {
    getBorrowingsCalls++;
    if (loadError != null) throw loadError!;
    return borrowRows ?? <Map<String, dynamic>>[];
  }

  @override
  Future<Map<String, dynamic>> submitBorrowRequest({
    int? equipmentId,
    String? otherEquipmentText,
    required int quantity,
    required String purpose,
    String fulfillmentMethod = 'Pickup',
    String? deliveryAddress,
    String borrowerType = 'Resident',
    String? organizationName,
  }) async {
    submitCalls++;
    lastPurpose = purpose;
    if (submitError != null) throw submitError!;
    return submitResult ??
        <String, dynamic>{
          'borrow_id': 99,
          'equipment_id': equipmentId,
          'other_equipment_text': otherEquipmentText,
          'quantity': quantity,
          'purpose': purpose,
          'status': 'Pending',
        };
  }
}

Map<String, dynamic> _equipmentRow(int id, String name, int qty) => <String, dynamic>{
      'equipment_id': id,
      'item_name': name,
      'available_quantity': qty,
    };

const _wheelchair = Equipment(id: 3, name: 'Wheelchair', availableQuantity: 2);

void main() {
  group('submitBorrowRequest', () {
    test('inserts optimistically, then replaces with the confirmed row', () async {
      final api = _FakeApi(submitResult: {
        'borrow_id': 42,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Pending',
      });
      final state = AppState(api);

      final result = state.submitBorrowRequest(
          item: _wheelchair, quantity: 1, purpose: 'Barangay drill');

      // Synchronously inserted, before the fake's Future even resolves.
      expect(state.borrowRequests, hasLength(1));
      expect(state.borrowRequests.single.id, isNull);

      final confirmed = await result;

      expect(confirmed, isNotNull);
      expect(confirmed!.id, 42);
      expect(state.borrowRequests, hasLength(1));
      expect(state.borrowRequests.single.id, 42);
    });

    test('a rejected request is rolled back, not left on screen', () async {
      // The exact bug class the comment in AppState.submitBorrowRequest names:
      // a stock-check 422 must not look like a filed request MDRRMO will
      // never see.
      final api = _FakeApi(submitError: const ApiException(
        'Only 1 of this item are available to borrow.',
      ));
      final state = AppState(api);

      final confirmed = await state.submitBorrowRequest(
          item: _wheelchair, quantity: 5, purpose: 'Barangay drill');

      expect(confirmed, isNull);
      expect(state.borrowRequests, isEmpty);
      expect(state.takeError(), 'Only 1 of this item are available to borrow.');
    });

    test('the optimistic row names itself from the tapped catalogue item', () {
      // POST's 201 has no equipment relation loaded (see borrow_models_test),
      // so until the server answers the only source of the name is the item
      // the resident actually tapped.
      final api = _FakeApi();
      final state = AppState(api);

      state.submitBorrowRequest(
          item: _wheelchair, quantity: 1, purpose: 'Barangay drill');

      expect(state.borrowRequests.single.equipmentName, 'Wheelchair');
    });

    test('the optimistic row carries the purpose, and it reaches the API', () async {
      // The optimistic card is what the resident sees until the server
      // answers; without this it shows the request with no reason attached.
      final api = _FakeApi();
      final state = AppState(api);

      final pending = state.submitBorrowRequest(
          item: _wheelchair, quantity: 1, purpose: 'Evacuation centre setup');

      expect(state.borrowRequests.single.purpose, 'Evacuation centre setup');

      await pending;

      expect(api.lastPurpose, 'Evacuation centre setup');
      expect(state.borrowRequests.single.purpose, 'Evacuation centre setup');
    });
  });

  group('loadEquipment', () {
    test('populates the catalogue and resolves names on rows already on screen', () async {
      final api = _FakeApi(equipmentRows: [_equipmentRow(3, 'Wheelchair', 2)]);
      final state = AppState(api);

      // A row with no name, as if filed before the catalogue ever loaded.
      state.borrowRequests.add(const BorrowRequest(
        id: 7,
        equipmentId: 3,
        quantity: 1,
        status: BorrowStatus.pending,
      ));

      await state.loadEquipment();

      expect(state.equipment, hasLength(1));
      expect(state.equipment.single.name, 'Wheelchair');
      expect(state.borrowRequests.single.equipmentName, 'Wheelchair');
    });

    test('a failed fetch empties the catalogue and records the error', () async {
      final erroring = _FakeApiError();
      final state = AppState(erroring);

      await state.loadEquipment();

      expect(state.equipment, isEmpty);
      expect(state.equipmentError, isNotNull);
    });
  });

  group('loadBorrowRequests', () {
    test('overlapping fetches collapse into one', () async {
      final api = _FakeApi(borrowRows: [
        {'borrow_id': 1, 'equipment_id': 3, 'quantity': 1, 'status': 'Pending'},
      ]);
      final state = AppState(api);

      await Future.wait<void>([
        state.loadBorrowRequests(),
        state.loadBorrowRequests(),
        state.loadBorrowRequests(),
      ]);

      expect(api.getBorrowingsCalls, 1);
      expect(state.borrowRequests, hasLength(1));
    });

    test('a refetch does not wipe a request with no server id yet', () async {
      final api = _FakeApi(borrowRows: [
        {'borrow_id': 1, 'equipment_id': 3, 'quantity': 1, 'status': 'Pending'},
      ]);
      final state = AppState(api);
      final inFlight = const BorrowRequest(
        equipmentId: 3,
        quantity: 1,
        status: BorrowStatus.pending,
      );
      state.borrowRequests.add(inFlight);

      await state.loadBorrowRequests();

      expect(state.borrowRequests, hasLength(2));
      expect(identical(state.borrowRequests.first, inFlight), isTrue);
    });

    test('a silent poll failure raises no error but still flags offline', () async {
      final erroring = _FakeApiError();
      final state = AppState(erroring);

      await state.loadBorrowRequests(silent: true);

      expect(state.takeError(), isNull);
      expect(state.borrowIsOffline, isTrue);
    });

    test('borrowIsOffline is independent of the service-request flag', () async {
      // Fetched from a different screen at a different time — sharing one
      // flag would let a stale service-request poll clear this screen's
      // own banner.
      final erroring = _FakeApiError();
      final state = AppState(erroring);

      await state.loadBorrowRequests(silent: true);

      expect(state.borrowIsOffline, isTrue);
      expect(state.isOffline, isFalse);
    });
  });

  group('cancelBorrowRequest', () {
    BorrowRequest row(int id, BorrowStatus status) => BorrowRequest(
          id: id,
          equipmentId: 3,
          quantity: 1,
          status: status,
        );

    test('marks the row cancelled and tells the server which one', () async {
      final api = _FakeApi();
      final state = AppState(api);
      state.borrowRequests.add(row(11, BorrowStatus.pending));

      final ok = await state.cancelBorrowRequest(11);

      expect(ok, isTrue);
      expect(api.lastCancelledId, 11);
      expect(state.borrowRequests.single.status, BorrowStatus.cancelled);
    });

    test('an Approved request can still be withdrawn', () async {
      // The line is at Released, not at Pending — an approved loan the
      // resident no longer needs is exactly the case this route exists for.
      final api = _FakeApi();
      final state = AppState(api);
      state.borrowRequests.add(row(12, BorrowStatus.approved));

      expect(await state.cancelBorrowRequest(12), isTrue);
      expect(state.borrowRequests.single.status, BorrowStatus.cancelled);
    });

    test('a refusal puts the row back the way it was', () async {
      // Without the rollback the resident is left looking at a cancelled row
      // that MDRRMO still has open, with no way to undo it.
      final api = _FakeApi()..cancelError = const ApiException(
        'Only a pending or approved request can be cancelled. Call the office instead.',
      );
      final state = AppState(api);
      state.borrowRequests.add(row(13, BorrowStatus.approved));

      final ok = await state.cancelBorrowRequest(13);

      expect(ok, isFalse);
      expect(state.borrowRequests.single.status, BorrowStatus.approved);
      expect(state.takeError(),
          'Only a pending or approved request can be cancelled. Call the office instead.');
    });

    test('a row past Released is refused without calling the server', () async {
      final api = _FakeApi();
      final state = AppState(api);
      state.borrowRequests
        ..add(row(14, BorrowStatus.released))
        ..add(row(15, BorrowStatus.returned))
        ..add(row(16, BorrowStatus.denied))
        ..add(row(17, BorrowStatus.cancelled));

      for (final id in [14, 15, 16, 17]) {
        expect(await state.cancelBorrowRequest(id), isFalse, reason: 'id $id');
      }

      expect(api.cancelCalls, 0);
    });

    test('a row still in flight is refused with an answer, not silently', () async {
      // No server id means no record to cancel yet. Returning false alone
      // would leave the tap looking like it did nothing.
      final api = _FakeApi();
      final state = AppState(api);
      state.borrowRequests.add(const BorrowRequest(
        equipmentId: 3,
        quantity: 1,
        status: BorrowStatus.pending,
      ));

      expect(await state.cancelBorrowRequest(null), isFalse);
      expect(api.cancelCalls, 0);
      expect(state.takeError(), isNotNull);
    });
  });
}

/// A transport failure specifically — throws on every borrowing/equipment
/// call, the way a dead network does, so `borrowIsOffline` has something real
/// to derive from.
class _FakeApiError extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getEquipments() async {
    throw const ApiException('Cannot connect to server.');
  }

  @override
  Future<List<Map<String, dynamic>>> getBorrowings() async {
    throw const ApiException('Cannot connect to server.');
  }
}
