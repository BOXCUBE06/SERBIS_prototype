import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';

void main() {
  group('Equipment.fromJson', () {
    test('reads the catalogue shape', () {
      final item = Equipment.fromJson({
        'equipment_id': 3,
        'item_name': 'Wheelchair',
        'available_quantity': 4,
      });

      expect(item.id, 3);
      expect(item.name, 'Wheelchair');
      expect(item.availableQuantity, 4);
    });

    test('a string id/quantity still parses', () {
      // Some responses (and every cache round-trip) carry numbers as strings.
      final item = Equipment.fromJson({
        'equipment_id': '3',
        'item_name': 'Wheelchair',
        'available_quantity': '4',
      });

      expect(item.id, 3);
      expect(item.availableQuantity, 4);
    });
  });

  group('borrowStatusFromText', () {
    test('maps every backend word', () {
      expect(borrowStatusFromText('Pending'), BorrowStatus.pending);
      expect(borrowStatusFromText('Approved'), BorrowStatus.approved);
      expect(borrowStatusFromText('Released'), BorrowStatus.released);
      expect(borrowStatusFromText('Returned'), BorrowStatus.returned);
      expect(borrowStatusFromText('Denied'), BorrowStatus.denied);
    });

    test('an unrecognised word falls through to pending, not a crash', () {
      expect(borrowStatusFromText('SomethingNew'), BorrowStatus.pending);
    });
  });

  group('BorrowStatusX.isTerminal', () {
    test('only Returned and Denied are terminal', () {
      expect(BorrowStatus.pending.isTerminal, isFalse);
      expect(BorrowStatus.approved.isTerminal, isFalse);
      expect(BorrowStatus.released.isTerminal, isFalse);
      expect(BorrowStatus.returned.isTerminal, isTrue);
      expect(BorrowStatus.denied.isTerminal, isTrue);
    });
  });

  group('BorrowRequest.fromJson', () {
    test('GET /borrowings eager-loads the equipment relation', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 12,
        'equipment_id': 3,
        'quantity': 2,
        'status': 'Approved',
        'due_date': '2026-08-20',
        'created_at': '2026-08-10T08:00:00.000000Z',
        'released_at': null,
        'returned_at': null,
        'equipment': {'equipment_id': 3, 'item_name': 'Wheelchair', 'available_quantity': 2},
      });

      expect(request.id, 12);
      expect(request.equipmentId, 3);
      expect(request.quantity, 2);
      expect(request.status, BorrowStatus.approved);
      expect(request.equipmentName, 'Wheelchair');
      expect(request.dueDate, DateTime(2026, 8, 20));
    });

    test("POST's 201 has no equipment relation — name is null until resolved", () {
      // This is the exact shape EquipmentBorrowingController::store() returns:
      // the freshly created model, unloaded. AppState resolves the name
      // against the catalogue; the model itself must not invent one.
      final request = BorrowRequest.fromJson({
        'borrow_id': 12,
        'equipment_id': 3,
        'quantity': 2,
        'status': 'Pending',
      });

      expect(request.equipmentName, isNull);
    });

    test('a bare calendar due_date is read as the local date, not shifted by UTC', () {
      // The trap `_parseDate` exists for: parsing this as an instant and
      // calling toLocal() rolls a date back a day west of Greenwich.
      final request = BorrowRequest.fromJson({
        'borrow_id': 1,
        'equipment_id': 1,
        'quantity': 1,
        'status': 'Approved',
        'due_date': '2026-01-01',
      });

      expect(request.dueDate, DateTime(2026, 1, 1));
    });
  });

  group('BorrowRequestCache round trip', () {
    test('a filed request survives toCacheJson/fromCacheJson', () {
      final original = BorrowRequest(
        id: 12,
        equipmentId: 3,
        quantity: 2,
        status: BorrowStatus.approved,
        dueDate: DateTime(2026, 8, 20),
        createdAt: DateTime.utc(2026, 8, 10, 8),
        equipmentName: 'Wheelchair',
      );

      final restored = BorrowRequestCache.fromCacheJson(original.toCacheJson());

      expect(restored, isNotNull);
      expect(restored!.id, 12);
      expect(restored.equipmentId, 3);
      expect(restored.quantity, 2);
      expect(restored.status, BorrowStatus.approved);
      expect(restored.dueDate, DateTime(2026, 8, 20));
      expect(restored.equipmentName, 'Wheelchair');
    });

    test('an id-less (unconfirmed) row is never cached', () {
      // Mirrors the ServiceRequest cache rule: a row the server never
      // confirmed must not be resurrected on the next offline launch.
      expect(BorrowRequestCache.fromCacheJson({'equipment_id': 1, 'quantity': 1}), isNull);
    });

    test('an unreadable cache entry returns null rather than throwing', () {
      expect(BorrowRequestCache.fromCacheJson('not a map'), isNull);
      expect(BorrowRequestCache.fromCacheJson(null), isNull);
    });
  });
}
