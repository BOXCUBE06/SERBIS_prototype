
library serbis.models;

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/translations.dart';

/// `disapproved` is deliberately not folded into `cancelled`. They are opposite
/// actors: the resident withdraws a request, the MDRRMO refuses one. Telling a
/// resident they cancelled a request the agency turned down is wrong, and it
/// hides that there are remarks explaining the refusal.
enum ReqStatus { review, scheduled, completed, cancelled, disapproved }

extension ReqStatusX on ReqStatus {
  String get label {
    if (this == ReqStatus.review) {
      return 'Under review';
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
      return 'Pick-up & drop-off';
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
  final String name;
  final String? description;

  const ServiceCatalogItem({
    required this.id,
    required this.name,
    required this.nameLocalized,
    this.description,
    this.descriptionLocalized,
  });

  /// What the UI shows: the localized blurb where there is one, the English
  /// column otherwise.
  String get displayDescription => descriptionLocalized ?? description ?? '';

  /// The service's name in the language the catalogue was fetched in. The
  /// server resolves this from tbl_service_translations and falls back to
  /// English, so it is never blank.
  final String nameLocalized;

  /// The blurb in the same language. Falls back independently of the name: a
  /// locale can have a translated name and no translated description.
  final String? descriptionLocalized;

  factory ServiceCatalogItem.fromJson(Map<String, dynamic> json) {
    final idValue = json['service_id'] ?? json['id'];
    final id = idValue is int
        ? idValue
        : int.tryParse(idValue?.toString() ?? '') ?? 0;
    final name = (json['service_name'] ?? json['name'] ?? '') as String;
    final localized = json['name_localized'] as String?;
    final localizedDescription = json['description_localized'] as String?;
    return ServiceCatalogItem(
      id: id,
      name: name,
      // Older builds of the API send neither localized field.
      nameLocalized: localized == null || localized.isEmpty ? name : localized,
      description: json['description'] as String?,
      descriptionLocalized:
          localizedDescription == null || localizedDescription.isEmpty
              ? null
              : localizedDescription,
    );
  }

  // Keyed on the English name so a Tagalog label cannot change which form or
  // icon a service gets.
  ServiceFormKind get formKind => formKindForServiceName(name);
  IconData get icon => iconForServiceName(name);
}

/// Which guided form to show for a catalogue service. Named services reuse the
/// existing rich forms; anything unrecognised gets the generic description form,
/// so a service the admin adds later still works without a code change.
enum ServiceFormKind { ambulance, road, relief, generic }

ServiceFormKind formKindForServiceName(String name) {
  final n = name.toLowerCase();
  if (n.contains('ambulance') ||
      n.contains('medical') ||
      n.contains('health') ||
      n.contains('transfer')) {
    return ServiceFormKind.ambulance;
  }
  if (n.contains('road') ||
      n.contains('clearing') ||
      n.contains('debris') ||
      n.contains('tree')) {
    return ServiceFormKind.road;
  }
  if (n.contains('relief') ||
      n.contains('goods') ||
      n.contains('food') ||
      n.contains('sandbag')) {
    return ServiceFormKind.relief;
  }
  return ServiceFormKind.generic;
}

IconData iconForServiceName(String name) => badgeForServiceName(name).icon;

/// Icon and badge colours for a catalogue service, chosen together from one set
/// of keywords so a card can never pair one service's icon with another's
/// palette. Keyed on `service_name` because the catalogue has ten services and
/// [ServiceType] only enumerates six — four of them, including Fire Rescue and
/// Search and Rescue, have no enum member at all.
({IconData icon, Color bg, Color fg}) badgeForServiceName(String name) {
  final n = name.toLowerCase();
  if (n.contains('ambulance') || n.contains('medical') || n.contains('health')) {
    return (icon: Icons.local_hospital_rounded, bg: AppColors.red50, fg: AppColors.red600);
  }
  if (n.contains('fire')) {
    return (icon: Icons.local_fire_department_rounded, bg: AppColors.red50, fg: AppColors.red600);
  }
  if (n.contains('flood') || n.contains('evac')) {
    return (icon: Icons.water_rounded, bg: AppColors.blue50, fg: AppColors.blue600);
  }
  if (n.contains('road') || n.contains('debris') || n.contains('clearing')) {
    return (icon: Icons.construction_rounded, bg: AppColors.amber50, fg: AppColors.amber600);
  }
  if (n.contains('relief') || n.contains('goods') || n.contains('food')) {
    return (icon: Icons.inventory_2_rounded, bg: AppColors.green50, fg: AppColors.green700);
  }
  // Before the 'rescue' test on purpose: "Animal Rescue" contains "rescue", so
  // the general branch used to swallow it and render the Search-and-Rescue
  // icon. Specific names have to be matched ahead of the family keyword.
  if (n.contains('animal')) {
    return (icon: Icons.pets_rounded, bg: const Color(0xFFEDE7F6), fg: const Color(0xFF6A1B9A));
  }
  if (n.contains('search') || n.contains('rescue')) {
    return (icon: Icons.travel_explore_rounded, bg: AppColors.blue50, fg: AppColors.blue600);
  }
  if (n.contains('power') || n.contains('line') || n.contains('electric')) {
    return (icon: Icons.bolt_rounded, bg: AppColors.amber50, fg: AppColors.amber600);
  }
  if (n.contains('sandbag')) {
    return (icon: Icons.shield_rounded, bg: AppColors.green50, fg: AppColors.green700);
  }
  return (icon: Icons.emergency_rounded, bg: AppColors.grey50, fg: AppColors.inkMuted);
}

/// Best-effort [ServiceType] for a catalogue service. Only for the code paths
/// that still take an enum; display goes through `service_name` instead, which
/// is why nothing here has to invent a value for Fire Rescue.
ServiceType serviceTypeForServiceName(String name) {
  switch (formKindForServiceName(name)) {
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

  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];
  return '${months[local.month - 1]} ${local.day}, $clock';
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

  /// The untranslated name, kept alongside because the icon and colour are
  /// chosen by English keyword. Matching on the translated name silently drops
  /// every service to the default grey badge -- "Ambulansya / Tugong Medikal"
  /// does not contain "ambulance".
  final String? serviceNameEn;

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
    this.serviceNameEn,
  });

  bool get _hasServiceName => serviceName != null && serviceName!.isNotEmpty;

  /// The name the badge is chosen from: always English, falling back to the
  /// localized name only if the English one was never resolved.
  String? get _badgeKey {
    if (serviceNameEn != null && serviceNameEn!.isNotEmpty) {
      return serviceNameEn;
    }
    return _hasServiceName ? serviceName : null;
  }

  /// Title for a request card. Falls back to the enum only for a row with no
  /// resolved service, which now means a local row awaiting its first response.
  String displayTitle(bool filipino) =>
      _hasServiceName ? serviceName! : type.titleFor(filipino);

  IconData get displayIcon {
    final key = _badgeKey;
    return key == null ? type.icon : badgeForServiceName(key).icon;
  }

  Color get displayBg {
    final key = _badgeKey;
    return key == null ? type.bg : badgeForServiceName(key).bg;
  }

  Color get displayFg {
    final key = _badgeKey;
    return key == null ? type.fg : badgeForServiceName(key).fg;
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
    String? serviceNameEn,
  }) {
    return ServiceRequest(
      id: id ?? this.id,
      serviceId: serviceId,
      description: description,
      // Derived from the English name: the enum's keywords are English.
      type: serviceNameEn != null
          ? serviceTypeForServiceName(serviceNameEn)
          : type,
      serviceName: serviceName ?? this.serviceName,
      serviceNameEn: serviceNameEn ?? this.serviceNameEn,
      refNo: refNo,
      status: status ?? this.status,
      metaLines: metaLines ?? this.metaLines,
      note: note ?? this.note,
      cancellable: cancellable ?? this.cancellable,
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
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

    final statusText = (json['status'] as String? ?? 'pending').toLowerCase();
    final status = getStatusFromText(statusText);

    final isActive = status == ReqStatus.review || status == ReqStatus.scheduled;

    return ServiceRequest(
      id: id,
      serviceId: serviceId,
      description: description,
      // Was hardcoded to ServiceType.inquiry, which labelled every server-loaded
      // row -- ambulance requests included -- "Information Inquiry".
      type: serviceName == null
          ? ServiceType.inquiry
          : serviceTypeForServiceName(serviceName),
      serviceName: serviceName,
      // The embedded relation is the untranslated column, so it doubles as the
      // badge key until the catalogue supplies a localized name.
      serviceNameEn: serviceName,
      refNo: id != null ? 'SR-$id' : '',
      status: status,
      metaLines: description == null ? [] : [description],
      note: note,
      cancellable: isActive,
      // Laravel serialises timestamps as UTC ISO strings; without toLocal()
      // every timeline entry would read eight hours early in the Philippines.
      createdAt: _parseTimestamp(json['created_at']),
      updatedAt: _parseTimestamp(json['updated_at']),
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
        'service_name_en': serviceNameEn,
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
      serviceNameEn: json['service_name_en'] as String?,
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
  // Backend vocabulary: Pending / Responding / Resolved / Disapproved / Cancelled.
  // 'scheduled'/'dispatched'/'completed' are kept for legacy/local rows.
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