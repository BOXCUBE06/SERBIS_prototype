
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
  final List<ServiceCatalogItem> services = [];

  AppState(this._api);

  AppLanguage language = AppLanguage.english;
  int _refCounter = 101;

  /// Last failure worth showing the resident. The shell drains this into a
  /// snackbar; every one of these used to be discarded by a bare `catch (_) {}`,
  /// so a dead network and an empty list looked identical.
  String? lastError;

  /// Read-and-clear, so one failure produces exactly one snackbar.
  String? takeError() {
    final error = lastError;
    lastError = null;
    return error;
  }

  void _fail(Object error) {
    lastError = error is ApiException
        ? error.message
        : 'Something went wrong. Please try again.';
    notifyListeners();
  }

  Future<void> loadServices() async {
    try {
      final list = await _api.getServices();
      services
        ..clear()
        ..addAll(list.map(ServiceCatalogItem.fromJson));
      notifyListeners();
    } catch (_) {
      // Deliberately not routed to `lastError`: the services screen renders its
      // own inline "couldn't load, retry" panel off an empty list, and a
      // snackbar on top of it would report the same failure twice. A 401 still
      // reaches the shell through ApiService.onUnauthorized.
      services.clear();
      notifyListeners();
    }
  }

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
    } catch (e) {
      _fail(e);
    }
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
    } catch (e) {
      // The optimistic row never reached the server, so drop it. Leaving it in
      // place is what made a 422 "No available vehicles at this time." look like
      // a filed request that MDRRMO would never see.
      requests.remove(request);
      _fail(e);
    }
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
    } catch (e) {
      // Put the request back the way it was — the server still has it open, and
      // showing it as cancelled would strand the resident with no way to undo.
      final index = requests.indexWhere((item) => item.refNo == refNo);
      if (index != -1) {
        requests[index] = current;
      }
      _fail(e);
    }
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