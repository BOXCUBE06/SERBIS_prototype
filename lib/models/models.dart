import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/strings.dart';

/// Status of a submitted service request.
enum ReqStatus { review, scheduled, completed, cancelled }

extension ReqStatusX on ReqStatus {
  String get label => switch (this) {
        ReqStatus.review => 'Under review',
        ReqStatus.scheduled => 'Scheduled',
        ReqStatus.completed => 'Completed',
        ReqStatus.cancelled => 'Cancelled',
      };

  /// Translated status label. Pass `appState.language == AppLanguage.filipino`.
  String labelFor(bool filipino) => switch (this) {
        ReqStatus.review => tr(filipino, 'status.review'),
        ReqStatus.scheduled => tr(filipino, 'status.scheduled'),
        ReqStatus.completed => tr(filipino, 'status.completed'),
        ReqStatus.cancelled => tr(filipino, 'status.cancelled'),
      };

  Color get bg => switch (this) {
        ReqStatus.review => AppColors.blue50,
        ReqStatus.scheduled => AppColors.amber50,
        ReqStatus.completed => AppColors.green50,
        ReqStatus.cancelled => AppColors.grey50,
      };

  Color get fg => switch (this) {
        ReqStatus.review => AppColors.blue600,
        ReqStatus.scheduled => AppColors.amber600,
        ReqStatus.completed => AppColors.green700,
        ReqStatus.cancelled => AppColors.inkFaint,
      };
}

/// The five service request categories residents can submit.
enum ServiceType { ambulance, transfer, road, relief, inquiry }

extension ServiceTypeX on ServiceType {
  String get title => switch (this) {
        ServiceType.ambulance => 'Medical Transport / Ambulance',
        ServiceType.transfer => 'Hospital Transfer',
        ServiceType.road => 'Road Clearing',
        ServiceType.relief => 'Relief Goods',
        ServiceType.inquiry => 'Information Inquiry',
      };

  String get subtitle => switch (this) {
        ServiceType.ambulance => 'Pick-up & drop-off',
        ServiceType.transfer => 'Incl. dialysis patients',
        ServiceType.road => 'Debris, fallen trees',
        ServiceType.relief => 'Assistance request',
        ServiceType.inquiry => 'General question to MDRRMO',
      };

  /// Translated display title. Pass `appState.language == AppLanguage.filipino`.
  String titleFor(bool filipino) => switch (this) {
        ServiceType.ambulance => tr(filipino, 'type.ambulance.title'),
        ServiceType.transfer => tr(filipino, 'type.transfer.title'),
        ServiceType.road => tr(filipino, 'type.road.title'),
        ServiceType.relief => tr(filipino, 'type.relief.title'),
        ServiceType.inquiry => tr(filipino, 'type.inquiry.title'),
      };

  /// Translated subtitle. Pass `appState.language == AppLanguage.filipino`.
  String subtitleFor(bool filipino) => switch (this) {
        ServiceType.ambulance => tr(filipino, 'type.ambulance.subtitle'),
        ServiceType.transfer => tr(filipino, 'type.transfer.subtitle'),
        ServiceType.road => tr(filipino, 'type.road.subtitle'),
        ServiceType.relief => tr(filipino, 'type.relief.subtitle'),
        ServiceType.inquiry => tr(filipino, 'type.inquiry.subtitle'),
      };

  IconData get icon => switch (this) {
        ServiceType.ambulance => Icons.local_hospital_rounded,
        ServiceType.transfer => Icons.local_hospital_outlined,
        ServiceType.road => Icons.construction_rounded,
        ServiceType.relief => Icons.inventory_2_rounded,
        ServiceType.inquiry => Icons.help_outline_rounded,
      };

  Color get bg => switch (this) {
        ServiceType.ambulance => AppColors.red50,
        ServiceType.transfer => AppColors.blue50,
        ServiceType.road => AppColors.amber50,
        ServiceType.relief => AppColors.green50,
        ServiceType.inquiry => AppColors.grey50,
      };

  Color get fg => switch (this) {
        ServiceType.ambulance => AppColors.red600,
        ServiceType.transfer => AppColors.blue600,
        ServiceType.road => AppColors.amber600,
        ServiceType.relief => AppColors.green700,
        ServiceType.inquiry => AppColors.inkMuted,
      };
}

/// A single step in a request's status timeline.
class TimelineStep {
  final String title;
  final String time;
  final RequestStepState state;

  const TimelineStep(this.title, this.time, this.state);
}

enum RequestStepState { done, current, pending }

/// A mock service request shown on the Track screen.
class ServiceRequest {
  final ServiceType type;
  final String refNo;
  final ReqStatus status;
  final List<String> metaLines;
  final List<TimelineStep> timeline;
  final String? note;
  final bool cancellable;

  const ServiceRequest({
    required this.type,
    required this.refNo,
    required this.status,
    required this.metaLines,
    required this.timeline,
    this.note,
    this.cancellable = false,
  });

  ServiceRequest copyWith({
    ReqStatus? status,
    List<String>? metaLines,
    List<TimelineStep>? timeline,
    String? note,
    bool? cancellable,
  }) {
    return ServiceRequest(
      type: type,
      refNo: refNo,
      status: status ?? this.status,
      metaLines: metaLines ?? this.metaLines,
      timeline: timeline ?? this.timeline,
      note: note ?? this.note,
      cancellable: cancellable ?? this.cancellable,
    );
  }
}
