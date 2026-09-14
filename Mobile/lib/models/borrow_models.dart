
library serbis.models.borrow;

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// A row from `GET /api/equipments` — the catalogue a resident borrows from.
/// Read-only here: writing stock belongs to the admin panel.
class Equipment {
  final int id;
  final String name;
  final int availableQuantity;

  const Equipment({
    required this.id,
    required this.name,
    required this.availableQuantity,
  });

  factory Equipment.fromJson(Map<String, dynamic> json) {
    final idValue = json['equipment_id'] ?? json['id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '') ?? 0;
    final qtyValue = json['available_quantity'];
    final qty = qtyValue is int ? qtyValue : int.tryParse(qtyValue?.toString() ?? '') ?? 0;

    return Equipment(
      id: id,
      name: (json['item_name'] ?? '') as String,
      availableQuantity: qty,
    );
  }
}

/// Mirrors the backend's state machine exactly (`Pending` ->
/// `Approved`/`Denied`, `Approved` -> `Released`/`Denied`, `Released` ->
/// `Returned`). The resident moves a row through exactly one of these edges —
/// Pending or Approved to `Cancelled`, via PATCH /borrowings/{id}/cancel — and
/// only reads the rest.
enum BorrowStatus { pending, approved, released, returned, denied, cancelled }

/// `cancelled` has to be a real case rather than something the UI infers.
/// Everything unknown falls to `pending` below, so a row the server calls
/// 'Cancelled' would otherwise read as still open on this screen and offer its
/// own Cancel button a second time.
BorrowStatus borrowStatusFromText(String statusText) {
  switch (statusText) {
    case 'Approved':
      return BorrowStatus.approved;
    case 'Released':
      return BorrowStatus.released;
    case 'Returned':
      return BorrowStatus.returned;
    case 'Denied':
      return BorrowStatus.denied;
    case 'Cancelled':
      return BorrowStatus.cancelled;
    default:
      return BorrowStatus.pending;
  }
}

extension BorrowStatusX on BorrowStatus {
  String get label {
    switch (this) {
      case BorrowStatus.pending:
        return 'Pending review';
      case BorrowStatus.approved:
        return 'Approved — awaiting pickup';
      case BorrowStatus.released:
        return 'Released to you';
      case BorrowStatus.returned:
        return 'Returned';
      case BorrowStatus.denied:
        return 'Not approved';
      case BorrowStatus.cancelled:
        return 'Cancelled by you';
    }
  }

  Color get bg {
    switch (this) {
      case BorrowStatus.pending:
        return AppColors.amber50;
      case BorrowStatus.approved:
        return AppColors.blue50;
      case BorrowStatus.released:
        return AppColors.blue50;
      case BorrowStatus.returned:
        return AppColors.green50;
      case BorrowStatus.denied:
        return AppColors.red50;
      // Neutral, not red: the resident withdrew this themselves, so it is not
      // a refusal and must not read like one beside a Denied row.
      case BorrowStatus.cancelled:
        return AppColors.grey50;
    }
  }

  Color get fg {
    switch (this) {
      case BorrowStatus.pending:
        return AppColors.amber600;
      case BorrowStatus.approved:
        return AppColors.blue600;
      case BorrowStatus.released:
        return AppColors.blue600;
      case BorrowStatus.returned:
        return AppColors.green700;
      case BorrowStatus.denied:
        return AppColors.red600;
      case BorrowStatus.cancelled:
        return AppColors.inkMuted;
    }
  }

  bool get isTerminal =>
      this == BorrowStatus.returned ||
      this == BorrowStatus.denied ||
      this == BorrowStatus.cancelled;

  /// Mirrors EquipmentBorrowingController::CANCELLABLE_FROM. The backend is
  /// still the authority — this only decides whether the button is drawn, and
  /// a 422 from the route is handled either way.
  bool get isCancellable =>
      this == BorrowStatus.pending || this == BorrowStatus.approved;
}

/// A resident's own equipment loan. Read-only after filing apart from one
/// move: PATCH /borrowings/{id}/cancel withdraws a request that is still
/// Pending or Approved, the same line [ServiceRequest] draws at Responding.
/// Everything else on the record still belongs to `is.admin`.
class BorrowRequest {
  final int? id;

  /// Null when the request names something the MDRRMO inventory does not list
  /// — see [otherEquipmentText]. The column is nullable on the server and the
  /// table enforces that exactly one of the two is set, so null here is a
  /// normal state and not a parse failure. It used to fall back to `0`, which
  /// would now read as a real equipment row that does not exist.
  final int? equipmentId;

  /// What the resident typed when the item they need is not in the catalogue.
  /// Exactly one of this and [equipmentId] is non-null.
  final String? otherEquipmentText;

  final int quantity;

  /// What the resident said the item is for. `POST /borrowings` requires it,
  /// so anything filed from this app has one; rows filed before the column
  /// existed do not, and the card falls back rather than showing an empty line.
  final String? purpose;
  final BorrowStatus status;
  final String? denialReason;
  final DateTime? dueDate;
  final DateTime? createdAt;
  final DateTime? releasedAt;
  final DateTime? returnedAt;

  /// The item's name at the moment this row was built. Resolved against the
  /// catalogue the same way `ServiceRequest.serviceName` is: `POST`'s 201
  /// returns the row unloaded, with no `equipment` relation embedded, so a
  /// freshly filed request has nowhere else to read this from until the next
  /// `GET /borrowings` (which does eager-load it).
  final String? equipmentName;

  /// Whether staff photographed the item at handover. `has_release_photo` and
  /// `has_return_photo` are appended by the model; the path columns behind them
  /// are hidden, since a private-disk path is not something a client is handed.
  /// The bytes come from GET /borrowings/{id}/photo/{stage}. A loan with no
  /// photo is a normal, complete record.
  final bool hasReleasePhoto;
  final bool hasReturnPhoto;

  /// What to print on the card. Prefers the catalogue name, falls back to what
  /// the resident wrote, and only then to the generic word — so an
  /// uncatalogued request shows the item the resident actually asked for
  /// instead of reading as a row whose name failed to load.
  String get itemLabel => equipmentName ?? otherEquipmentText ?? 'Equipment';

  const BorrowRequest({
    this.id,
    this.equipmentId,
    this.otherEquipmentText,
    required this.quantity,
    this.purpose,
    required this.status,
    this.denialReason,
    this.dueDate,
    this.createdAt,
    this.releasedAt,
    this.returnedAt,
    this.equipmentName,
    this.hasReleasePhoto = false,
    this.hasReturnPhoto = false,
  });

  BorrowRequest copyWith({
    int? id,
    BorrowStatus? status,
    String? denialReason,
    DateTime? dueDate,
    DateTime? releasedAt,
    DateTime? returnedAt,
    String? equipmentName,
  }) {
    return BorrowRequest(
      id: id ?? this.id,
      equipmentId: equipmentId,
      otherEquipmentText: otherEquipmentText,
      quantity: quantity,
      purpose: purpose,
      status: status ?? this.status,
      denialReason: denialReason ?? this.denialReason,
      dueDate: dueDate ?? this.dueDate,
      createdAt: createdAt,
      releasedAt: releasedAt ?? this.releasedAt,
      returnedAt: returnedAt ?? this.returnedAt,
      equipmentName: equipmentName ?? this.equipmentName,
      hasReleasePhoto: hasReleasePhoto,
      hasReturnPhoto: hasReturnPhoto,
    );
  }

  factory BorrowRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['borrow_id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '');

    // No `?? 0` fallback any more: a null equipment_id is a real, expected
    // value for an uncatalogued request, and zero would claim an inventory row
    // that cannot exist.
    final equipIdValue = json['equipment_id'];
    final equipmentId =
        equipIdValue is int ? equipIdValue : int.tryParse(equipIdValue?.toString() ?? '');

    final qtyValue = json['quantity'];
    final quantity = qtyValue is int ? qtyValue : int.tryParse(qtyValue?.toString() ?? '') ?? 0;

    // GET/show eager-load the relation; POST's 201 does not. See the field
    // comment on `equipmentName`.
    final equipment = json['equipment'];
    final equipmentName =
        equipment is Map<String, dynamic> ? equipment['item_name'] as String? : null;

    return BorrowRequest(
      id: id,
      equipmentId: equipmentId,
      otherEquipmentText: json['other_equipment_text'] as String?,
      quantity: quantity,
      purpose: json['purpose'] as String?,
      status: borrowStatusFromText((json['status'] as String?) ?? 'Pending'),
      denialReason: json['denial_reason'] as String?,
      dueDate: _parseDate(json['due_date']),
      createdAt: _parseInstant(json['created_at']),
      releasedAt: _parseInstant(json['released_at']),
      returnedAt: _parseInstant(json['returned_at']),
      equipmentName: equipmentName,
      hasReleasePhoto: json['has_release_photo'] == true,
      hasReturnPhoto: json['has_return_photo'] == true,
    );
  }
}

/// Everything needed to redraw a borrow card with no network. See
/// `state/borrow_cache.dart`.
extension BorrowRequestCache on BorrowRequest {
  Map<String, dynamic> toCacheJson() => <String, dynamic>{
        'id': id,
        'equipment_id': equipmentId,
        'other_equipment_text': otherEquipmentText,
        'quantity': quantity,
        'purpose': purpose,
        'status': status.name,
        'denial_reason': denialReason,
        'due_date': dueDate == null
            ? null
            : '${dueDate!.year.toString().padLeft(4, '0')}-'
                '${dueDate!.month.toString().padLeft(2, '0')}-'
                '${dueDate!.day.toString().padLeft(2, '0')}',
        'created_at': createdAt?.toIso8601String(),
        'released_at': releasedAt?.toIso8601String(),
        'returned_at': returnedAt?.toIso8601String(),
        'equipment_name': equipmentName,
        'has_release_photo': hasReleasePhoto,
        'has_return_photo': hasReturnPhoto,
      };

  /// Rebuilds a cached row, or null for an entry this version cannot read. A
  /// cache is not a contract: one bad row must not take the rest with it.
  static BorrowRequest? fromCacheJson(Object? json) {
    if (json is! Map) return null;

    final id = json['id'];
    // Only server-confirmed rows are cached — an id-less row was never filed.
    if (id is! int) return null;

    return BorrowRequest(
      id: id,
      equipmentId: json['equipment_id'] is int ? json['equipment_id'] as int : null,
      otherEquipmentText: json['other_equipment_text'] as String?,
      quantity: json['quantity'] is int ? json['quantity'] as int : 0,
      purpose: json['purpose'] as String?,
      status: BorrowStatus.values.firstWhere(
        (value) => value.name == json['status'],
        orElse: () => BorrowStatus.pending,
      ),
      denialReason: json['denial_reason'] as String?,
      dueDate: _parseDate(json['due_date']),
      createdAt: _parseInstant(json['created_at']),
      releasedAt: _parseInstant(json['released_at']),
      returnedAt: _parseInstant(json['returned_at']),
      equipmentName: json['equipment_name'] as String?,
      hasReleasePhoto: json['has_release_photo'] == true,
      hasReturnPhoto: json['has_return_photo'] == true,
    );
  }
}

/// `due_date` is a bare calendar date (`date:Y-m-d` on the model) and must be
/// read as one — parsing it as an instant and calling `.toLocal()` can roll it
/// back a day west of UTC. `created_at`/`released_at`/`returned_at` are real
/// timestamps and go through [_parseInstant] instead.
DateTime? _parseDate(dynamic value) {
  if (value is! String || value.isEmpty) return null;
  final parts = value.split('-');
  if (parts.length != 3) return null;
  final y = int.tryParse(parts[0]);
  final m = int.tryParse(parts[1]);
  final d = int.tryParse(parts[2].substring(0, 2));
  if (y == null || m == null || d == null) return null;
  return DateTime(y, m, d);
}

DateTime? _parseInstant(dynamic value) {
  if (value is! String || value.isEmpty) return null;
  return DateTime.tryParse(value)?.toLocal();
}
