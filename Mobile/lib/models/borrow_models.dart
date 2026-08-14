
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

/// Mirrors the backend's five-value state machine exactly (`Pending` ->
/// `Approved`/`Denied`, `Approved` -> `Released`/`Denied`, `Released` ->
/// `Returned`). The resident never moves a row through this — only reads it.
enum BorrowStatus { pending, approved, released, returned, denied }

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
    }
  }

  bool get isTerminal => this == BorrowStatus.returned || this == BorrowStatus.denied;
}

/// A resident's own equipment loan. There is no resident-facing cancel route
/// on the backend — `borrowings` only exposes `update`/`destroy` to
/// `is.admin` — so unlike [ServiceRequest] this is read-only after filing.
class BorrowRequest {
  final int? id;
  final int equipmentId;
  final int quantity;
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

  const BorrowRequest({
    this.id,
    required this.equipmentId,
    required this.quantity,
    required this.status,
    this.denialReason,
    this.dueDate,
    this.createdAt,
    this.releasedAt,
    this.returnedAt,
    this.equipmentName,
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
      quantity: quantity,
      status: status ?? this.status,
      denialReason: denialReason ?? this.denialReason,
      dueDate: dueDate ?? this.dueDate,
      createdAt: createdAt,
      releasedAt: releasedAt ?? this.releasedAt,
      returnedAt: returnedAt ?? this.returnedAt,
      equipmentName: equipmentName ?? this.equipmentName,
    );
  }

  factory BorrowRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['borrow_id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '');

    final equipIdValue = json['equipment_id'];
    final equipmentId =
        equipIdValue is int ? equipIdValue : int.tryParse(equipIdValue?.toString() ?? '') ?? 0;

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
      quantity: quantity,
      status: borrowStatusFromText((json['status'] as String?) ?? 'Pending'),
      denialReason: json['denial_reason'] as String?,
      dueDate: _parseDate(json['due_date']),
      createdAt: _parseInstant(json['created_at']),
      releasedAt: _parseInstant(json['released_at']),
      returnedAt: _parseInstant(json['returned_at']),
      equipmentName: equipmentName,
    );
  }
}

/// Everything needed to redraw a borrow card with no network. See
/// `state/borrow_cache.dart`.
extension BorrowRequestCache on BorrowRequest {
  Map<String, dynamic> toCacheJson() => <String, dynamic>{
        'id': id,
        'equipment_id': equipmentId,
        'quantity': quantity,
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
      equipmentId: json['equipment_id'] is int ? json['equipment_id'] as int : 0,
      quantity: json['quantity'] is int ? json['quantity'] as int : 0,
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
