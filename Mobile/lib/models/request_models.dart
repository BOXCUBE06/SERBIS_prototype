
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
  });

  ServiceRequest copyWith({
    int? id,
    ReqStatus? status,
    List<String>? metaLines,
    List<TimelineStep>? timeline,
    String? note,
    bool? cancellable,
  }) {
    return ServiceRequest(
      id: id ?? this.id,
      serviceId: serviceId,
      description: description,
      type: type,
      refNo: refNo,
      status: status ?? this.status,
      metaLines: metaLines ?? this.metaLines,
      timeline: timeline ?? this.timeline,
      note: note ?? this.note,
      cancellable: cancellable ?? this.cancellable,
    );
  }

  factory ServiceRequest.fromJson(Map<String, dynamic> json) {
    final idValue = json['id'];
    final id = idValue is int ? idValue : int.tryParse(idValue?.toString() ?? '');

    final serviceIdValue = json['service_id'];
    final serviceId = serviceIdValue is int
        ? serviceIdValue
        : int.tryParse(serviceIdValue?.toString() ?? '');

    final description = json['description'] as String?;
    final note = json['remarks'] as String?;

    final statusText = (json['status'] as String? ?? 'pending').toLowerCase();
    final status = getStatusFromText(statusText);

    final isActive = status == ReqStatus.review || status == ReqStatus.scheduled;

    return ServiceRequest(
      id: id,
      serviceId: serviceId,
      description: description,
      type: ServiceType.inquiry,
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
  if (statusText == 'scheduled' || statusText == 'dispatched') {
    return ReqStatus.scheduled;
  }

  if (statusText == 'completed') {
    return ReqStatus.completed;
  }

  if (statusText == 'cancelled') {
    return ReqStatus.cancelled;
  }

  return ReqStatus.review;
}