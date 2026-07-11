/// Domain models for the SERBIS service-request system.
///
/// Defines the core data shapes used across the app: the categories of
/// service a resident can request ([ServiceType]), the lifecycle status of
/// a request ([ReqStatus]), the request itself ([ServiceRequest]), and its
/// status history ([TimelineStep] / [RequestStepState]).
///
/// These are plain data classes with no business logic; request creation,
/// storage, and mutation live in [AppState] (see `lib/state/app_state.dart`).
library serbis.models;

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/translations.dart';

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

/// The six service request categories residents can submit.
enum ServiceType { ambulance, transfer, road, relief, inquiry, items }

extension ServiceTypeX on ServiceType {
  String get title => switch (this) {
        ServiceType.ambulance => 'Medical Transport / Ambulance',
        ServiceType.transfer => 'Hospital Transfer',
        ServiceType.road => 'Road Clearing',
        ServiceType.relief => 'Relief Goods',
        ServiceType.inquiry => 'Information Inquiry',
        ServiceType.items => 'Equipment / Item Request',
      };

  String get subtitle => switch (this) {
        ServiceType.ambulance => 'Pick-up & drop-off',
        ServiceType.transfer => 'Incl. dialysis patients',
        ServiceType.road => 'Debris, fallen trees',
        ServiceType.relief => 'Assistance request',
        ServiceType.inquiry => 'General question to MDRRMO',
        ServiceType.items => 'Wheelchair, stretcher & more',
      };

  /// Translated display title. Pass `appState.language == AppLanguage.filipino`.
  String titleFor(bool filipino) => switch (this) {
        ServiceType.ambulance => tr(filipino, 'type.ambulance.title'),
        ServiceType.transfer => tr(filipino, 'type.transfer.title'),
        ServiceType.road => tr(filipino, 'type.road.title'),
        ServiceType.relief => tr(filipino, 'type.relief.title'),
        ServiceType.inquiry => tr(filipino, 'type.inquiry.title'),
        ServiceType.items => tr(filipino, 'type.items.title'),
      };

  /// Translated subtitle. Pass `appState.language == AppLanguage.filipino`.
  String subtitleFor(bool filipino) => switch (this) {
        ServiceType.ambulance => tr(filipino, 'type.ambulance.subtitle'),
        ServiceType.transfer => tr(filipino, 'type.transfer.subtitle'),
        ServiceType.road => tr(filipino, 'type.road.subtitle'),
        ServiceType.relief => tr(filipino, 'type.relief.subtitle'),
        ServiceType.inquiry => tr(filipino, 'type.inquiry.subtitle'),
        ServiceType.items => tr(filipino, 'type.items.subtitle'),
      };

  IconData get icon => switch (this) {
        ServiceType.ambulance => Icons.local_hospital_rounded,
        ServiceType.transfer => Icons.local_hospital_outlined,
        ServiceType.road => Icons.construction_rounded,
        ServiceType.relief => Icons.inventory_2_rounded,
        ServiceType.inquiry => Icons.help_outline_rounded,
        ServiceType.items => Icons.medical_services_rounded,
      };

  Color get bg => switch (this) {
        ServiceType.ambulance => AppColors.red50,
        ServiceType.transfer => AppColors.blue50,
        ServiceType.road => AppColors.amber50,
        ServiceType.relief => AppColors.green50,
        ServiceType.inquiry => AppColors.grey50,
        ServiceType.items => const Color(0xFFEDE7F6),
      };

  Color get fg => switch (this) {
        ServiceType.ambulance => AppColors.red600,
        ServiceType.transfer => AppColors.blue600,
        ServiceType.road => AppColors.amber600,
        ServiceType.relief => AppColors.green700,
        ServiceType.inquiry => AppColors.inkMuted,
        ServiceType.items => const Color(0xFF6A1B9A),
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

/// A service request shown on the Track screen.
class ServiceRequest {
  /// The backend's real numeric primary key (tbl_service_request id).
  /// Null for a request that only exists locally and hasn't been
  /// confirmed by the server yet — the [refNo] is shown to the user in
  /// the meantime, but [id] is what must be sent back to the server for
  /// any update/cancel call.
  final int? id;

  /// The backend's tbl_services.service_id this request was filed under.
  final int? serviceId;

  /// Free-text description sent to the backend (ServiceRequestController
  /// requires this field).
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
      id:          id ?? this.id,
      serviceId:   serviceId,
      description: description,
      type:        type,
      refNo:       refNo,
      status:      status     ?? this.status,
      metaLines:   metaLines  ?? this.metaLines,
      timeline:    timeline   ?? this.timeline,
      note:        note       ?? this.note,
      cancellable: cancellable ?? this.cancellable,
    );
  }

  /// Builds a [ServiceRequest] from the JSON map returned by the Laravel
  /// backend's GET /api/service-requests endpoint (see
  /// ServiceRequestController@index / @store).
  ///
  /// The backend returns raw columns from tbl_service_request
  /// (id, service_id, description, status, created_at, ...), not the
  /// 'type'/'ref_no'/'meta' shape this app originally mocked — so most of
  /// this is now reconstructed rather than read directly.
  factory ServiceRequest.fromJson(Map<String, dynamic> json) {
    final id = json['id'] as int? ??
        int.tryParse(json['id']?.toString() ?? '');
    final serviceId = json['service_id'] as int? ??
        int.tryParse(json['service_id']?.toString() ?? '');

    // The backend doesn't send a ServiceType-shaped 'type' field directly —
    // if your Service model / relation include a name you can map it here.
    // Falling back to 'inquiry' until this mapping is wired up against
    // whatever tbl_services actually returns (e.g. json['service']['name']).
    final type = ServiceType.inquiry;

    // Map the server's status string to our ReqStatus enum
    final statusStr = (json['status'] as String? ?? 'pending').toLowerCase();
    final status = switch (statusStr) {
      'scheduled' || 'dispatched' => ReqStatus.scheduled,
      'completed' => ReqStatus.completed,
      'cancelled' => ReqStatus.cancelled,
      _ => ReqStatus.review,
    };

    return ServiceRequest(
      id:          id,
      serviceId:   serviceId,
      description: json['description'] as String?,
      type:        type,
      refNo:       id != null ? 'SR-$id' : '',
      status:      status,
      metaLines: [
        if (json['description'] != null) json['description'] as String,
      ],
      timeline:    const [], // timeline detail loaded separately if needed
      note:        json['remarks'] as String?,
      cancellable: status == ReqStatus.review || status == ReqStatus.scheduled,
    );
  }
}