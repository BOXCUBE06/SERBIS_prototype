
library serbis.state.app_state;

import 'package:flutter/foundation.dart';
import '../models/request_models.dart';
import 'api_service.dart';

enum AppLanguage { english, filipino }

extension AppLanguageX on AppLanguage {
  String get label {
    if (this == AppLanguage.english) {
      return 'English';
    }
    return 'Filipino';
  }
}

class AppState extends ChangeNotifier {
  final ApiService _api;
  final List<ServiceRequest> requests = [];

  AppState(this._api);

  AppLanguage language = AppLanguage.english;
  int _refCounter = 101;

  void setLanguage(AppLanguage value) {
    if (language == value) {
      return;
    }

    language = value;
    notifyListeners();
  }

  String nextRefNo() {
    final ref = 'QR-2026-${_refCounter.toString().padLeft(3, '0')}';
    _refCounter = _refCounter + 1;
    return ref;
  }

  Future<void> loadRequests() async {
    try {
      final list = await _api.getRequests();
      requests.clear();
      for (final item in list) {
        requests.add(ServiceRequest.fromJson(item));
      }
      notifyListeners();
    } catch (_) {}
  }

  Future<void> addRequest(
    ServiceRequest request, {
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
  }) async {
    requests.insert(0, request);
    notifyListeners();

    if (request.serviceId == null || request.description == null) {
      return;
    }

    try {
      final result = await _api.submitRequest(
        serviceId: request.serviceId!,
        description: request.description!,
        validIdFileBytes: validIdFileBytes,
        validIdFileName: validIdFileName,
        requiredVehicleType: requiredVehicleType,
      );

      final confirmed = ServiceRequest.fromJson(result);
      final index = requests.indexOf(request);
      if (index != -1) {
        requests[index] = confirmed;
        notifyListeners();
      }
    } catch (_) {}
  }

  Future<void> cancelRequest(String refNo) async {
    final index = requests.indexWhere((item) => item.refNo == refNo);
    if (index == -1) {
      return;
    }

    final current = requests[index];
    if (current.status == ReqStatus.cancelled ||
        current.status == ReqStatus.completed) {
      return;
    }

    requests[index] = current.copyWith(
      status: ReqStatus.cancelled,
      cancellable: false,
      note: 'You cancelled this request.',
      timeline: [
        ...current.timeline.where((step) => step.state == RequestStepState.done),
        const TimelineStep(
          'Request cancelled',
          'Just now',
          RequestStepState.done,
        ),
      ],
    );
    notifyListeners();

    if (current.id == null) {
      return;
    }

    try {
      await _api.cancelRequest(current.id!);
    } catch (_) {}
  }

  ServiceRequest? get activeRequest {
    if (requests.isEmpty) {
      return null;
    }

    for (final item in requests) {
      if (item.status == ReqStatus.review || item.status == ReqStatus.scheduled) {
        return item;
      }
    }

    return requests.first;
  }
}