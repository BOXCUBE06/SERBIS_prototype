
library serbis.models;

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/translations.dart';

enum ReqStatus { review, scheduled, completed, cancelled }

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
    this.description,
  });

  factory ServiceCatalogItem.fromJson(Map<String, dynamic> json) {
    final idValue = json['service_id'] ?? json['id'];
    final id = idValue is int
        ? idValue
        : int.tryParse(idValue?.toString() ?? '') ?? 0;
    return ServiceCatalogItem(
      id: id,
      name: (json['service_name'] ?? json['name'] ?? '') as String,
      description: json['description'] as String?,
    );
  }

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
  if (n.contains('search') || n.contains('rescue')) {
    return (icon: Icons.travel_explore_rounded, bg: AppColors.blue50, fg: AppColors.blue600);
  }
  if (n.contains('power') || n.contains('line') || n.contains('electric')) {
    return (icon: Icons.bolt_rounded, bg: AppColors.amber50, fg: AppColors.amber600);
  }
  if (n.contains('animal')) {
    return (icon: Icons.pets_rounded, bg: const Color(0xFFEDE7F6), fg: const Color(0xFF6A1B9A));
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

class ServiceRequest {
  final int? id;
  final int? serviceId;
  final String? description;
  final ServiceType type;
  final String refNo;
  final ReqStatus status;
  final List<String> metaLines;
  final List<TimelineStep> timeline;
  final String? note;
  final bool cancellable;

  /// The catalogue's own `service_name`, when the row came with one. Display
  /// prefers this over [type]: the enum has six values against the catalogue's
  /// ten, so it cannot name a Fire Rescue or a Sandbagging request at all.
  final String? serviceName;

  const ServiceRequest({
    this.id,
    this.serviceId,
    this.description,
    required this.type,
    required this.refNo,
    required this.status,
    required this.metaLines,
    required this.timeline,
    this.note,
    this.cancellable = false,
    this.serviceName,
  });

  bool get _hasServiceName => serviceName != null && serviceName!.isNotEmpty;

  /// Title for a request card. Falls back to the enum only for a row with no
  /// resolved service, which now means a local row awaiting its first response.
  String displayTitle(bool filipino) =>
      _hasServiceName ? serviceName! : type.titleFor(filipino);

  IconData get displayIcon =>
      _hasServiceName ? badgeForServiceName(serviceName!).icon : type.icon;

  Color get displayBg =>
      _hasServiceName ? badgeForServiceName(serviceName!).bg : type.bg;

  Color get displayFg =>
      _hasServiceName ? badgeForServiceName(serviceName!).fg : type.fg;

  ServiceRequest copyWith({
    int? id,
    ReqStatus? status,
    List<String>? metaLines,
    List<TimelineStep>? timeline,
    String? note,
    bool? cancellable,
    String? serviceName,
  }) {
    return ServiceRequest(
      id: id ?? this.id,
      serviceId: serviceId,
      description: description,
      type: serviceName != null ? serviceTypeForServiceName(serviceName) : type,
      serviceName: serviceName ?? this.serviceName,
      refNo: refNo,
      status: status ?? this.status,
      metaLines: metaLines ?? this.metaLines,
      timeline: timeline ?? this.timeline,
      note: note ?? this.note,
      cancellable: cancellable ?? this.cancellable,
    );
  }

  factory ServiceRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['request_id'] ?? json['id'];
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
      refNo: id != null ? 'SR-$id' : '',
      status: status,
      metaLines: description == null ? [] : [description],
      timeline: const [],
      note: note,
      cancellable: isActive,
    );
  }
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

  if (statusText == 'cancelled' || statusText == 'disapproved') {
    return ReqStatus.cancelled;
  }

  // Pending and anything unknown fall through to "Under review".
  return ReqStatus.review;
}