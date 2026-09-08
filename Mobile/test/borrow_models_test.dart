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
      expect(borrowStatusFromText('Cancelled'), BorrowStatus.cancelled);
    });

    test('an unrecognised word falls through to pending, not a crash', () {
      expect(borrowStatusFromText('SomethingNew'), BorrowStatus.pending);
    });

    test('Cancelled does not fall through to pending', () {
      // The default branch is why the enum case had to exist: without it a
      // withdrawn request would read as open, and the card would offer its
      // own Cancel button on a row that is already cancelled.
      expect(borrowStatusFromText('Cancelled'), isNot(BorrowStatus.pending));
      expect(BorrowStatus.cancelled.isCancellable, isFalse);
    });
  });

  group('BorrowStatusX.isTerminal', () {
    test('only Returned, Denied and Cancelled are terminal', () {
      expect(BorrowStatus.pending.isTerminal, isFalse);
      expect(BorrowStatus.approved.isTerminal, isFalse);
      expect(BorrowStatus.released.isTerminal, isFalse);
      expect(BorrowStatus.returned.isTerminal, isTrue);
      expect(BorrowStatus.denied.isTerminal, isTrue);
      expect(BorrowStatus.cancelled.isTerminal, isTrue);
    });
  });

  group('BorrowStatusX.isCancellable', () {
    test('mirrors CANCELLABLE_FROM: Pending and Approved only', () {
      expect(BorrowStatus.pending.isCancellable, isTrue);
      expect(BorrowStatus.approved.isCancellable, isTrue);
      expect(BorrowStatus.released.isCancellable, isFalse);
      expect(BorrowStatus.returned.isCancellable, isFalse);
      expect(BorrowStatus.denied.isCancellable, isFalse);
      expect(BorrowStatus.cancelled.isCancellable, isFalse);
    });
  });

  group('BorrowRequest.fromJson', () {
    test('GET /borrowings eager-loads the equipment relation', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 12,
        'equipment_id': 3,
        'quantity': 2,
        'status': 'Approved',
        'purpose': 'Barangay flood drill',
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
      expect(request.purpose, 'Barangay flood drill');
      expect(request.dueDate, DateTime(2026, 8, 20));
    });

    test('a row filed before the purpose column existed reads as null', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 12,
        'equipment_id': 3,
        'quantity': 2,
        'status': 'Approved',
      });

      expect(request.purpose, isNull);
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
        purpose: 'Barangay flood drill',
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
      expect(restored.purpose, 'Barangay flood drill');
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

    test('an uncatalogued row survives the cache with its text intact', () {
      final request = BorrowRequest(
        id: 12,
        otherEquipmentText: 'Chainsaw with a 20-inch bar',
        quantity: 1,
        purpose: 'Clearing a fallen acacia',
        status: BorrowStatus.pending,
      );

      final restored = BorrowRequestCache.fromCacheJson(request.toCacheJson())!;

      expect(restored.equipmentId, isNull);
      expect(restored.otherEquipmentText, 'Chainsaw with a 20-inch bar');
      expect(restored.itemLabel, 'Chainsaw with a 20-inch bar');
    });
  });

  group('BorrowRequest item source', () {
    test('a null equipment_id reads as null, not as zero', () {
      // It used to fall back to 0, which now reads as a real inventory row
      // that cannot exist.
      final request = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': null,
        'other_equipment_text': 'Chainsaw with a 20-inch bar',
        'quantity': 1,
        'status': 'Pending',
      });

      expect(request.equipmentId, isNull);
      expect(request.otherEquipmentText, 'Chainsaw with a 20-inch bar');
    });

    test('itemLabel prefers the catalogue name over the free text', () {
      // The server's CHECK constraint makes both-set impossible, so this is
      // about precedence being stated rather than incidental.
      final request = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'equipment': {'item_name': 'Wheelchair'},
        'other_equipment_text': 'ignored',
        'quantity': 1,
        'status': 'Pending',
      });

      expect(request.itemLabel, 'Wheelchair');
    });

    test('itemLabel falls back to the free text, then to the generic word', () {
      final uncatalogued = BorrowRequest.fromJson({
        'borrow_id': 9,
        'other_equipment_text': 'Chainsaw with a 20-inch bar',
        'quantity': 1,
        'status': 'Pending',
      });
      expect(uncatalogued.itemLabel, 'Chainsaw with a 20-inch bar');

      // POST's 201 returns the row with no `equipment` relation embedded, so a
      // freshly filed catalogued request has neither name until the next GET.
      final unresolved = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Pending',
      });
      expect(unresolved.itemLabel, 'Equipment');
    });
  });

  group('BorrowRequest handover photos', () {
    test('the flags follow the path columns, not the status', () {
      final photographed = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Returned',
        'release_photo_path': 'borrowing-photos/9/release.jpg',
        'return_photo_path': 'borrowing-photos/9/return.jpg',
      });
      expect(photographed.hasReleasePhoto, isTrue);
      expect(photographed.hasReturnPhoto, isTrue);

      // A returned loan nobody photographed is a normal, complete record.
      final unphotographed = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Returned',
      });
      expect(unphotographed.hasReleasePhoto, isFalse);
      expect(unphotographed.hasReturnPhoto, isFalse);
    });

    test('an empty path is no photo', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Released',
        'release_photo_path': '',
      });
      expect(request.hasReleasePhoto, isFalse);
    });

    test('the flags survive the cache', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Released',
        'release_photo_path': 'borrowing-photos/9/release.jpg',
      });

      final restored = BorrowRequestCache.fromCacheJson(request.toCacheJson())!;
      expect(restored.hasReleasePhoto, isTrue);
      expect(restored.hasReturnPhoto, isFalse);
    });

    test('a cancel keeps the flags on the row it rewrites', () {
      final request = BorrowRequest.fromJson({
        'borrow_id': 9,
        'equipment_id': 3,
        'quantity': 1,
        'status': 'Released',
        'release_photo_path': 'borrowing-photos/9/release.jpg',
      });

      expect(request.copyWith(status: BorrowStatus.cancelled).hasReleasePhoto, isTrue);
    });
  });
}
