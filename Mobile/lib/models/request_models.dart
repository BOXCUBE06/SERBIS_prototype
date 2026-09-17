
library serbis.models;

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/translations.dart';

/// `disapproved` is deliberately not folded into `cancelled`. They are opposite
/// actors: the resident withdraws a request, the MDRRMO refuses one. Telling a
/// resident they cancelled a request the agency turned down is wrong, and it
/// hides that there are remarks explaining the refusal.
enum ReqStatus { review, booked, scheduled, completed, cancelled, disapproved }

extension ReqStatusX on ReqStatus {
  String get label {
    if (this == ReqStatus.review) {
      return 'Under review';
    }
    if (this == ReqStatus.booked) {
      return 'Booked';
    }
    if (this == ReqStatus.scheduled) {
      return 'Scheduled';
    }
    if (this == ReqStatus.completed) {
      return 'Completed';
    }
    if (this == ReqStatus.disapproved) {
      return 'Not approved';
    }
    return 'Cancelled';
  }

  String labelFor(bool filipino) {
    if (this == ReqStatus.review) {
      return tr(filipino, 'status.review');
    }
    if (this == ReqStatus.booked) {
      return tr(filipino, 'status.booked');
    }
    if (this == ReqStatus.scheduled) {
      return tr(filipino, 'status.scheduled');
    }
    if (this == ReqStatus.completed) {
      return tr(filipino, 'status.completed');
    }
    if (this == ReqStatus.disapproved) {
      return tr(filipino, 'status.disapproved');
    }
    return tr(filipino, 'status.cancelled');
  }

  Color get bg {
    if (this == ReqStatus.review) {
      return AppColors.blue50;
    }
    // The violet already in the palette on the animal-rescue badge. Booked has
    // to be told apart from Scheduled at a glance -- they are adjacent states
    // and amber is taken -- and the theme carries no sixth semantic hue.
    if (this == ReqStatus.booked) {
      return const Color(0xFFEDE7F6);
    }
    if (this == ReqStatus.scheduled) {
      return AppColors.amber50;
    }
    if (this == ReqStatus.completed) {
      return AppColors.green50;
    }
    if (this == ReqStatus.disapproved) {
      return AppColors.red50;
    }
    return AppColors.grey50;
  }

  Color get fg {
    if (this == ReqStatus.review) {
      return AppColors.blue600;
    }
    if (this == ReqStatus.booked) {
      return const Color(0xFF6A1B9A);
    }
    if (this == ReqStatus.scheduled) {
      return AppColors.amber600;
    }
    if (this == ReqStatus.completed) {
      return AppColors.green700;
    }
    if (this == ReqStatus.disapproved) {
      return AppColors.red600;
    }
    return AppColors.inkFaint;
  }
}

enum ServiceType { ambulance, transfer, road, relief, inquiry, items }

extension ServiceTypeX on ServiceType {
  String get title {
    if (this == ServiceType.ambulance) {
      return 'Medical Transport / Ambulance';
    }
    if (this == ServiceType.transfer) {
      return 'Hospital Transfer';
    }
    if (this == ServiceType.road) {
      return 'Road Clearing';
    }
    if (this == ServiceType.relief) {
      return 'Relief Goods';
    }
    if (this == ServiceType.inquiry) {
      return 'Information Inquiry';
    }
    return 'Equipment / Item Request';
  }

  String get subtitle {
    if (this == ServiceType.ambulance) {
      return 'Patient transport';
    }
    if (this == ServiceType.transfer) {
      return 'Incl. dialysis patients';
    }
    if (this == ServiceType.road) {
      return 'Debris, fallen trees';
    }
    if (this == ServiceType.relief) {
      return 'Assistance request';
    }
    if (this == ServiceType.inquiry) {
      return 'General question to MDRRMO';
    }
    return 'Wheelchair, stretcher & more';
  }

  String titleFor(bool filipino) {
    if (this == ServiceType.ambulance) {
      return tr(filipino, 'type.ambulance.title');
    }
    if (this == ServiceType.transfer) {
      return tr(filipino, 'type.transfer.title');
    }
    if (this == ServiceType.road) {
      return tr(filipino, 'type.road.title');
    }
    if (this == ServiceType.relief) {
      return tr(filipino, 'type.relief.title');
    }
    if (this == ServiceType.inquiry) {
      return tr(filipino, 'type.inquiry.title');
    }
    return tr(filipino, 'type.items.title');
  }

  String subtitleFor(bool filipino) {
    if (this == ServiceType.ambulance) {
      return tr(filipino, 'type.ambulance.subtitle');
    }
    if (this == ServiceType.transfer) {
      return tr(filipino, 'type.transfer.subtitle');
    }
    if (this == ServiceType.road) {
      return tr(filipino, 'type.road.subtitle');
    }
    if (this == ServiceType.relief) {
      return tr(filipino, 'type.relief.subtitle');
    }
    if (this == ServiceType.inquiry) {
      return tr(filipino, 'type.inquiry.subtitle');
    }
    return tr(filipino, 'type.items.subtitle');
  }

  IconData get icon {
    if (this == ServiceType.ambulance) {
      return Icons.local_hospital_rounded;
    }
    if (this == ServiceType.transfer) {
      return Icons.local_hospital_outlined;
    }
    if (this == ServiceType.road) {
      return Icons.construction_rounded;
    }
    if (this == ServiceType.relief) {
      return Icons.inventory_2_rounded;
    }
    if (this == ServiceType.inquiry) {
      return Icons.help_outline_rounded;
    }
    return Icons.medical_services_rounded;
  }

  Color get bg {
    if (this == ServiceType.ambulance) {
      return AppColors.red50;
    }
    if (this == ServiceType.transfer) {
      return AppColors.blue50;
    }
    if (this == ServiceType.road) {
      return AppColors.amber50;
    }
    if (this == ServiceType.relief) {
      return AppColors.green50;
    }
    if (this == ServiceType.inquiry) {
      return AppColors.grey50;
    }
    return const Color(0xFFEDE7F6);
  }

  Color get fg {
    if (this == ServiceType.ambulance) {
      return AppColors.red600;
    }
    if (this == ServiceType.transfer) {
      return AppColors.blue600;
    }
    if (this == ServiceType.road) {
      return AppColors.amber600;
    }
    if (this == ServiceType.relief) {
      return AppColors.green700;
    }
    if (this == ServiceType.inquiry) {
      return AppColors.inkMuted;
    }
    return const Color(0xFF6A1B9A);
  }
}

/// A real service row from `GET /api/services` — the single source of truth for
/// `service_id`. Replaces the old hardcoded `ServiceType -> id` map that filed an
/// ambulance request as *Flood Evacuation* (every id passed `exists` validation,
/// so the misfiling was silent).
class ServiceCatalogItem {
  final int id;

  /// The English name from `tbl_services.service_name`. Shown only when this
  /// build has no entry for [code] — a service the MDRRMO added after it
  /// shipped. Everything else is named from the app's own translation table.
  final String name;

  /// The English blurb, used on the same terms as [name].
  final String? description;

  /// The server's stable identifier — `tbl_services.code`, a slug like
  /// `road-clearing`. Unlike [id] it does not depend on insertion order, and
  /// unlike [name] an admin cannot rewrite it from the panel. Everything this
  /// app decides about a service is keyed on it.
  ///
  /// Empty for a row from an API too old to send one, which resolves to the
  /// generic form and the generic badge.
  final String code;

  const ServiceCatalogItem({
    required this.id,
    required this.name,
    this.code = '',
    this.description,
  });

  /// The client-only "Others" tile appended after the real catalogue loads —
  /// not a row in `tbl_services`, so [id] is a sentinel the submit path
  /// recognises and omits from the request rather than sending. Mirrors how
  /// an uncatalogued equipment borrow leaves `equipment_id` null and carries
  /// the item's name as free text instead; here the free text is whatever
  /// the resident types into the generic description form, since
  /// `service_id` null already makes `description` required server-side.
  static const int othersId = -1;

  const ServiceCatalogItem.others()
      : id = othersId,
        name = 'Others',
        code = 'others',
        description = 'Something not covered by the services above.';

  bool get isOthers => id == othersId;

  /// The name to show, resolved from the app's own translation table by [code].
  ///
  /// The API's `name_localized` is no longer read. It came from a translations
  /// table in the database, which meant the label on an emergency form could be
  /// edited between two launches of the app; and it forced every screen to
  /// re-fetch the whole catalogue on a language switch, because the language
  /// lived in the query string.
  String displayName(bool filipino) => serviceNameFor(filipino, code, name);

  /// The blurb under the name, resolved the same way. Empty rather than null:
  /// it is rendered straight into a Text widget.
  String displayDescription(bool filipino) =>
      serviceDescriptionFor(filipino, code, description ?? '');

  factory ServiceCatalogItem.fromJson(Map<String, dynamic> json) {
    final idValue = json['service_id'] ?? json['id'];
    final id = idValue is int
        ? idValue
        : int.tryParse(idValue?.toString() ?? '') ?? 0;
    final name = (json['service_name'] ?? json['name'] ?? '') as String;
    // `name_localized` and `description_localized` are deliberately not read.
    // The server still sends them; nothing here depends on them any more.
    return ServiceCatalogItem(
      id: id,
      name: name,
      code: (json['code'] as String?) ?? '',
      description: json['description'] as String?,
    );
  }

  // Keyed on the code, not on either name. The display name is admin-editable:
  // renaming "Road Clearing" to "Street Clearing" in the panel used to drop the
  // service to the generic form and a grey badge, with nothing reporting it.
  ServiceFormKind get formKind => formKindForServiceCode(code);
  IconData get icon => iconForServiceCode(code);
}

/// Which guided form to show for a catalogue service. The four kinds map onto
/// the service codes below; anything else — a service the MDRRMO adds in the
/// panel, or a payload from an API too old to send a code — gets the generic
/// description form, so the app files it correctly without a code change.
enum ServiceFormKind { ambulance, road, relief, generic }

/// Exact match on `tbl_services.code`. Deliberately not substring matching, and
/// deliberately with no fall-through to the display name: the name is
/// admin-editable, so keying on it meant a rename in the panel silently changed
/// which form a resident was given.
ServiceFormKind formKindForServiceCode(String code) {
  switch (code) {
    case 'ambulance-medical-response':
      return ServiceFormKind.ambulance;
    case 'road-clearing':
    case 'debris-removal':
      return ServiceFormKind.road;
    case 'relief-goods-distribution':
    case 'sandbagging':
      return ServiceFormKind.relief;
    // power-line-repair and animal-rescue have no guided form of their own and
    // take the generic one, which is what the keyword matching resolved them
    // to as well.
    default:
      return ServiceFormKind.generic;
  }
}

IconData iconForServiceCode(String code) => badgeForServiceCode(code).icon;

/// Icon and badge colours, chosen together from one switch so a card can never
/// pair one service's icon with another's palette.
///
/// Keyed on the code for the same reason as [formKindForServiceCode], plus one
/// the name could never solve: matching on the *translated* name dropped every
/// service to the grey default, because "Ambulansya / Tugong Medikal" contains
/// no English keyword. A code is the same string in every language.
({IconData icon, Color bg, Color fg}) badgeForServiceCode(String code) {
  switch (code) {
    case 'ambulance-medical-response':
      return (icon: Icons.local_hospital_rounded, bg: AppColors.red50, fg: AppColors.red600);
    case 'road-clearing':
    case 'debris-removal':
      return (icon: Icons.construction_rounded, bg: AppColors.amber50, fg: AppColors.amber600);
    case 'relief-goods-distribution':
      return (icon: Icons.inventory_2_rounded, bg: AppColors.green50, fg: AppColors.green700);
    case 'animal-rescue':
      return (icon: Icons.pets_rounded, bg: const Color(0xFFEDE7F6), fg: const Color(0xFF6A1B9A));
    case 'power-line-repair':
      return (icon: Icons.bolt_rounded, bg: AppColors.amber50, fg: AppColors.amber600);
    case 'sandbagging':
      return (icon: Icons.shield_rounded, bg: AppColors.green50, fg: AppColors.green700);
    case 'others':
      return (icon: Icons.more_horiz_rounded, bg: AppColors.grey50, fg: AppColors.inkMuted);
    default:
      return (icon: Icons.emergency_rounded, bg: AppColors.grey50, fg: AppColors.inkMuted);
  }
}

/// Best-effort [ServiceType] for a catalogue service. Only for the code paths
/// that still take an enum; display goes through the service name instead,
/// which is why nothing here has to invent a value for Fire Rescue.
ServiceType serviceTypeForServiceCode(String code) {
  switch (formKindForServiceCode(code)) {
    case ServiceFormKind.ambulance:
      return ServiceType.ambulance;
    case ServiceFormKind.road:
      return ServiceType.road;
    case ServiceFormKind.relief:
      return ServiceType.relief;
    case ServiceFormKind.generic:
      return ServiceType.inquiry;
  }
}

class TimelineStep {
  final String title;
  final String time;
  final RequestStepState state;

  const TimelineStep(this.title, this.time, this.state);
}

enum RequestStepState { done, current, pending }

/// The month abbreviations every date in this app is rendered with.
///
/// Left in English deliberately — see [formatTimelineTime] for why — and
/// shared so the three formatters below cannot drift apart.
const _monthAbbrev = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/// Renders a timestamp the way the request cards do: "Today, 3:04 PM" for
/// today, "Aug 1, 3:04 PM" otherwise.
///
/// Only the word "Today" is translated. Month abbreviations are left in
/// English because that is what the rest of the app and the LGU's own forms
/// use, and inventing Filipino ones here would be a guess.
String formatTimelineTime(DateTime at, bool filipino) {
  final local = at.toLocal();
  final hour12 = local.hour % 12 == 0 ? 12 : local.hour % 12;
  final minute = local.minute.toString().padLeft(2, '0');
  final period = local.hour >= 12 ? 'PM' : 'AM';
  final clock = '$hour12:$minute $period';

  final now = DateTime.now();
  final isToday = local.year == now.year &&
      local.month == now.month &&
      local.day == now.day;

  if (isToday) {
    return '${tr(filipino, 'timeline.today')}, $clock';
  }

  return '${_monthAbbrev[local.month - 1]} ${local.day}, $clock';
}

/// Renders a timestamp for a booking confirmation: "Aug 1, 2026, 3:04 PM".
///
/// [formatTimelineTime] is wrong here on purpose, not by oversight — it drops
/// the year, which is fine for a timeline entry that is always read within
/// days of "now" but wrong for a booking that can sit weeks out. A resident
/// re-opening the app in January must not read an August confirmation as
/// last year's.
String formatBookingConfirmationTime(DateTime at, bool filipino) {
  final local = at.toLocal();
  final hour12 = local.hour % 12 == 0 ? 12 : local.hour % 12;
  final minute = local.minute.toString().padLeft(2, '0');
  final period = local.hour >= 12 ? 'PM' : 'AM';

  return '${_monthAbbrev[local.month - 1]} ${local.day}, ${local.year}, '
      '$hour12:$minute $period';
}

/// Renders a calendar day with no clock: "Sep 12, 2026".
///
/// A loan's due date is a day, not an instant, so [formatBookingConfirmationTime]
/// would append a time of day the borrowing record does not carry. The year is
/// kept for the same reason that formatter keeps it: a loan can outlive the
/// month it was filed in.
String formatDueDate(DateTime at) {
  final local = at.toLocal();
  return '${_monthAbbrev[local.month - 1]} ${local.day}, ${local.year}';
}

/// Matches the admin panel's `dueLabel` (`EquipmentBorrowingView.vue`) —
/// "N days overdue" / "Due today" / "Due tomorrow" / "Due in N days" — so a
/// borrower and staff read the same urgency off the same date. Both
/// midnights are taken locally, same as the panel's `dueDelta`. [now]
/// defaults to the device clock and is injectable for tests.
String dueLabel(DateTime due, [DateTime? now]) {
  final today = now ?? DateTime.now();
  final todayMidnight = DateTime(today.year, today.month, today.day);
  final dueMidnight = DateTime(due.year, due.month, due.day);
  final delta = dueMidnight.difference(todayMidnight).inDays;
  if (delta < 0) {
    final overdueDays = -delta;
    return '$overdueDays day${overdueDays == 1 ? '' : 's'} overdue';
  }
  if (delta == 0) return 'Due today';
  if (delta == 1) return 'Due tomorrow';
  return 'Due in $delta days';
}

/// Same tiering as [dueLabel] and the same reason it stays English rather
/// than going through [tr] — MDRRMO feedback, 2026-09-18, extending the
/// equipment due countdown to a confirmed ambulance booking's own scheduled
/// time. Never called once a booking is already overdue: [ServiceRequest]'s
/// own display logic gates this behind `!isOverdue`, which is the box that
/// covers the past-due case with its own message.
String scheduledCountdownLabel(DateTime scheduledAt, [DateTime? now]) {
  final today = now ?? DateTime.now();
  final todayMidnight = DateTime(today.year, today.month, today.day);
  final local = scheduledAt.toLocal();
  final schedMidnight = DateTime(local.year, local.month, local.day);
  final delta = schedMidnight.difference(todayMidnight).inDays;

  if (delta <= 0) return 'Scheduled today';
  if (delta == 1) return 'Scheduled tomorrow';
  return 'Scheduled in $delta days';
}

class ServiceRequest {
  final int? id;
  final int? serviceId;
  final String? description;
  final ServiceType type;
  final String refNo;
  final ReqStatus status;
  final List<String> metaLines;
  final String? note;
  final bool cancellable;

  /// When the request was filed. The server's `created_at`; for a row that has
  /// not reached the server yet, the moment the resident pressed submit.
  final DateTime? createdAt;

  /// The row's `updated_at`. This is the last time *any* column changed, not
  /// specifically the status, so the timeline presents it as when the request
  /// last moved rather than claiming a precise status-change time. A real
  /// status-change log would need a resident-scoped history endpoint.
  final DateTime? updatedAt;

  /// The service's name in the resident's language, when the row resolved to
  /// one. Display prefers this over [type]: the enum has six values against the
  /// catalogue's ten, so it cannot name a Fire Rescue or a Sandbagging request.
  final String? serviceName;

  /// The service's stable code, kept alongside the name because the icon,
  /// colour and [type] are chosen from it. Null for a row whose service the app
  /// has not resolved yet — a locally filed request before its first refresh,
  /// or a cached row written by a build that stored the English name here
  /// instead. Such a row shows the neutral badge until the next fetch.
  final String? serviceCode;

  /// The ambulance booking's own window start, distinct from [createdAt] (when
  /// it was filed). Null means "as soon as you can" — an ordinary, unscheduled
  /// request, which is every request this app has ever sent. Deliberately a
  /// real field rather than a line folded into [description]: a prose date is
  /// not a value anything can compare, filter or re-send, and a resident's
  /// booking confirmation would be reading it back out of free text.
  final DateTime? scheduledAt;

  const ServiceRequest({
    this.id,
    this.serviceId,
    this.description,
    required this.type,
    required this.refNo,
    required this.status,
    required this.metaLines,
    this.note,
    this.cancellable = false,
    this.createdAt,
    this.updatedAt,
    this.serviceName,
    this.serviceCode,
    this.scheduledAt,
  });

  /// True once a Booked slot's own window has passed with nobody moving the
  /// request off Booked -- no server job watches for this and no
  /// notification fires, so this is purely a client-side "flag it" read of
  /// [status] and [scheduledAt], not a guarantee the booking was missed.
  bool get isOverdue =>
      status == ReqStatus.booked &&
      scheduledAt != null &&
      scheduledAt!.isBefore(DateTime.now());

  bool get _hasServiceName => serviceName != null && serviceName!.isNotEmpty;

  /// The code the badge is chosen from. There is deliberately no fallback to
  /// either name: a name-keyed badge is what made a rename in the admin panel
  /// change a request's icon, and reinstating it here would bring that back for
  /// the request list alone.
  String? get _badgeKey =>
      serviceCode != null && serviceCode!.isNotEmpty ? serviceCode : null;

  /// Title for a request card. Falls back to the enum only for a row with no
  /// resolved service, which now means a local row awaiting its first response.
  String displayTitle(bool filipino) =>
      _hasServiceName ? serviceName! : type.titleFor(filipino);

  IconData get displayIcon {
    final key = _badgeKey;
    return key == null ? type.icon : badgeForServiceCode(key).icon;
  }

  Color get displayBg {
    final key = _badgeKey;
    return key == null ? type.bg : badgeForServiceCode(key).bg;
  }

  Color get displayFg {
    final key = _badgeKey;
    return key == null ? type.fg : badgeForServiceCode(key).fg;
  }

  /// The progress timeline, derived rather than stored.
  ///
  /// It used to be a list built once at submit time and left empty by
  /// [ServiceRequest.fromJson], so it existed only for rows created in the
  /// current session and vanished on relaunch -- exactly when a resident most
  /// wants to know what is happening. Deriving it means every row has one,
  /// including rows loaded from the server, and the steps cannot drift out of
  /// step with [status].
  ///
  /// Every time shown is a real timestamp. A step whose time is not known says
  /// so instead of printing a placeholder.
  List<TimelineStep> timelineFor(bool filipino) {
    final submitted = TimelineStep(
      tr(filipino, 'timeline.submitted'),
      createdAt == null
          ? tr(filipino, 'timeline.time_unknown')
          : formatTimelineTime(createdAt!, filipino),
      RequestStepState.done,
    );

    // `updated_at` equal to `created_at` means nothing has happened to the row
    // since it was filed, so there is no second timestamp to report.
    final movedAt = updatedAt != null &&
            createdAt != null &&
            updatedAt!.isAtSameMomentAs(createdAt!)
        ? null
        : updatedAt;
    final movedLabel = movedAt == null
        ? tr(filipino, 'timeline.time_unknown')
        : formatTimelineTime(movedAt, filipino);

    switch (status) {
      case ReqStatus.review:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.review'),
            tr(filipino, 'timeline.awaiting'),
            RequestStepState.current,
          ),
          TimelineStep(
            tr(filipino, 'timeline.completed'),
            tr(filipino, 'timeline.awaiting'),
            RequestStepState.pending,
          ),
        ];
      case ReqStatus.booked:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.booked'),
            movedLabel,
            RequestStepState.current,
          ),
          TimelineStep(
            tr(filipino, 'timeline.completed'),
            tr(filipino, 'timeline.awaiting'),
            RequestStepState.pending,
          ),
        ];
      case ReqStatus.scheduled:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.responding'),
            movedLabel,
            RequestStepState.current,
          ),
          TimelineStep(
            tr(filipino, 'timeline.completed'),
            tr(filipino, 'timeline.awaiting'),
            RequestStepState.pending,
          ),
        ];
      case ReqStatus.completed:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.completed'),
            movedLabel,
            RequestStepState.done,
          ),
        ];
      case ReqStatus.cancelled:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.cancelled'),
            movedLabel,
            RequestStepState.done,
          ),
        ];
      case ReqStatus.disapproved:
        return [
          submitted,
          TimelineStep(
            tr(filipino, 'timeline.disapproved'),
            movedLabel,
            RequestStepState.done,
          ),
        ];
    }
  }

  ServiceRequest copyWith({
    int? id,
    ReqStatus? status,
    List<String>? metaLines,
    String? note,
    bool? cancellable,
    DateTime? updatedAt,
    String? serviceName,
    String? serviceCode,
  }) {
    return ServiceRequest(
      id: id ?? this.id,
      serviceId: serviceId,
      description: description,
      // Derived from the code once the catalogue has named the row.
      type: serviceCode != null
          ? serviceTypeForServiceCode(serviceCode)
          : type,
      serviceName: serviceName ?? this.serviceName,
      serviceCode: serviceCode ?? this.serviceCode,
      refNo: refNo,
      status: status ?? this.status,
      metaLines: metaLines ?? this.metaLines,
      note: note ?? this.note,
      cancellable: cancellable ?? this.cancellable,
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
      scheduledAt: scheduledAt,
    );
  }

  factory ServiceRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['request_id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '');

    final serviceIdValue = json['service_id'];
    final serviceId = serviceIdValue is int
        ? serviceIdValue
        : int.tryParse(serviceIdValue?.toString() ?? '');

    final description = json['description'] as String?;
    final note = json['remarks'] as String?;

    // GET /api/service-requests eager-loads the relation, so the row names its
    // own service. POST's 201 does not (`store()` returns the model unloaded),
    // which is why AppState resolves service_id against the catalogue as well.
    final service = json['service'];
    final serviceName = service is Map<String, dynamic>
        ? service['service_name'] as String?
        : null;
    // GET /api/service-requests serialises the whole related row, so the code
    // rides along with the name.
    final serviceCode = service is Map<String, dynamic>
        ? service['code'] as String?
        : null;

    final statusText = (json['status'] as String? ?? 'pending').toLowerCase();
    final status = getStatusFromText(statusText);

    // Booked counts as active: an approved booking that has not been dispatched
    // is exactly the case a resident must still be able to withdraw.
    final isActive = status == ReqStatus.review ||
        status == ReqStatus.booked ||
        status == ReqStatus.scheduled;

    return ServiceRequest(
      id: id,
      serviceId: serviceId,
      description: description,
      // Was hardcoded to ServiceType.inquiry, which labelled every server-loaded
      // row -- ambulance requests included -- "Information Inquiry".
      type: serviceCode == null
          ? ServiceType.inquiry
          : serviceTypeForServiceCode(serviceCode),
      serviceName: serviceName,
      serviceCode: serviceCode,
      refNo: id != null ? 'SR-$id' : '',
      status: status,
      metaLines: description == null ? [] : [description],
      note: note,
      cancellable: isActive,
      // Laravel serialises timestamps as UTC ISO strings; without toLocal()
      // every timeline entry would read eight hours early in the Philippines.
      createdAt: _parseTimestamp(json['created_at']),
      updatedAt: _parseTimestamp(json['updated_at']),
      // Absent on every request that is not a booking, and on an ordinary
      // request from a server build that predates this column.
      scheduledAt: _parseTimestamp(json['scheduled_at']),
    );
  }
}

/// Everything needed to redraw a request card with no network, written to the
/// device after each successful fetch. See `state/request_cache.dart`.
extension ServiceRequestCache on ServiceRequest {
  Map<String, dynamic> toCacheJson() => <String, dynamic>{
        'id': id,
        'service_id': serviceId,
        'description': description,
        'type': type.name,
        'ref_no': refNo,
        'status': status.name,
        'meta_lines': metaLines,
        'note': note,
        'cancellable': cancellable,
        'created_at': createdAt?.toIso8601String(),
        'updated_at': updatedAt?.toIso8601String(),
        'service_name': serviceName,
        'service_code': serviceCode,
        'scheduled_at': scheduledAt?.toIso8601String(),
      };

  /// Rebuilds a cached row, or returns null for an entry this version of the
  /// app cannot read. A cache is not a contract: one unreadable row must not
  /// take the rest of the list with it.
  static ServiceRequest? fromCacheJson(Object? json) {
    if (json is! Map) return null;

    final id = json['id'];
    // Only server-confirmed rows are cached. An id-less row was never filed,
    // and showing one after a relaunch would claim a request that MDRRMO has
    // no record of.
    if (id is! int) return null;

    return ServiceRequest(
      id: id,
      serviceId: json['service_id'] is int ? json['service_id'] as int : null,
      description: json['description'] as String?,
      type: ServiceType.values.firstWhere(
        (value) => value.name == json['type'],
        orElse: () => ServiceType.inquiry,
      ),
      refNo: json['ref_no'] as String? ?? '',
      status: ReqStatus.values.firstWhere(
        (value) => value.name == json['status'],
        orElse: () => ReqStatus.review,
      ),
      metaLines: (json['meta_lines'] as List?)?.whereType<String>().toList() ?? const [],
      note: json['note'] as String?,
      cancellable: json['cancellable'] == true,
      createdAt: _parseTimestamp(json['created_at']),
      updatedAt: _parseTimestamp(json['updated_at']),
      serviceName: json['service_name'] as String?,
      // A cache written before requests carried a code has no such key. The
      // row reads back with a neutral badge and the next fetch relabels it,
      // which is preferable to reviving the name-keyed lookup for one release.
      serviceCode: json['service_code'] as String?,
      // Same tolerance as service_code: a row cached by a build before this
      // feature existed has no 'scheduled_at' key at all. json['scheduled_at']
      // reads as null rather than throwing, so that row comes back as an
      // ordinary unscheduled request instead of failing to parse.
      scheduledAt: _parseTimestamp(json['scheduled_at']),
    );
  }
}

/// Reads one of the row's timestamps, tolerating a null or an unparseable
/// value: a timeline with one honest step beats a crash on a malformed date.
DateTime? _parseTimestamp(dynamic value) {
  if (value is! String || value.isEmpty) {
    return null;
  }
  return DateTime.tryParse(value)?.toLocal();
}

ReqStatus getStatusFromText(String statusText) {
  // Backend vocabulary: Pending / Booked / Responding / Resolved / Disapproved /
  // Cancelled. 'scheduled'/'dispatched'/'completed' are kept for legacy/local
  // rows.
  //
  // 'booked' is deliberately NOT folded into the scheduled branch below.
  // Responding means a crew is already moving; Booked means a slot is held and
  // nothing has left the office yet, and the timeline renders the two
  // differently.
  if (statusText == 'booked') {
    return ReqStatus.booked;
  }

  if (statusText == 'scheduled' ||
      statusText == 'dispatched' ||
      statusText == 'responding') {
    return ReqStatus.scheduled;
  }

  if (statusText == 'completed' || statusText == 'resolved') {
    return ReqStatus.completed;
  }

  // Kept apart: 'cancelled' is the resident's own withdrawal, 'disapproved' is
  // the MDRRMO refusing. Folding them told the resident they had cancelled a
  // request the agency turned down.
  if (statusText == 'disapproved') {
    return ReqStatus.disapproved;
  }

  if (statusText == 'cancelled') {
    return ReqStatus.cancelled;
  }

  // Pending and anything unknown fall through to "Under review".
  return ReqStatus.review;
}