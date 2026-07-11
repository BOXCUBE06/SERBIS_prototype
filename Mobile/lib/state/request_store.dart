/// Shared application state for the authenticated portion of SERBIS.
///
/// [AppState] is the single in-memory source of truth for everything that
/// needs to be visible across multiple tabs at once: the resident's
/// submitted [ServiceRequest]s and their chosen [AppLanguage]. It is a
/// [ChangeNotifier], created once in [RootShell] (`lib/main.dart`) and
/// passed down to Home, Services, Track, Library, and Profile.
///
/// All request mutations are **optimistic** — the UI updates immediately
/// and the API call happens in the background. This keeps the UI feeling
/// fast even on a slow connection.
library serbis.state.app_state;

import 'package:flutter/foundation.dart';
import '../models/request_models.dart';
import 'api_service.dart';

/// The two languages the Safety Library content is available in.
enum AppLanguage { english, filipino }

extension AppLanguageX on AppLanguage {
  String get label => switch (this) {
        AppLanguage.english  => 'English',
        AppLanguage.filipino => 'Filipino',
      };
}

class AppState extends ChangeNotifier {
  final ApiService _api;

  AppState(this._api);

  /// All submitted requests — starts empty, populated by [loadRequests].
  final List<ServiceRequest> requests = [];

  AppLanguage language = AppLanguage.english;

  void setLanguage(AppLanguage value) {
    if (language == value) return;
    language = value;
    notifyListeners();
  }

  int _refCounter = 101;

  /// Generates a sequential local reference number, e.g. "QR-2026-101".
  /// Only used for display before the server responds with a real id —
  /// once the server confirms, the request's [ServiceRequest.refNo] is
  /// rebuilt from its real numeric id instead (see [addRequest]).
  String nextRefNo() {
    final ref = 'QR-2026-${_refCounter.toString().padLeft(3, '0')}';
    _refCounter++;
    return ref;
  }

  /// Loads all of the resident's service requests from the Laravel backend.
  /// Safe to call on startup or on pull-to-refresh.
  Future<void> loadRequests() async {
    try {
      final list = await _api.getRequests();
      requests
        ..clear()
        ..addAll(list.map(ServiceRequest.fromJson));
      notifyListeners();
    } catch (_) {
      // Network error — keep whatever is already in the list.
    }
  }

  /// Optimistically adds [request] to the top of the list, then sends it
  /// to the server. If the server call fails the request stays in the local
  /// list (the resident can retry on next launch via [loadRequests]).
  ///
  /// [validIdFileBytes]/[validIdFileName] are required by the backend
  /// (ServiceRequestController@store expects an uploaded 'valid_id' image,
  /// max 2MB, jpg/jpeg/png) — get these from an image/file picker on the
  /// submit screen before calling this.
  Future<void> addRequest(
    ServiceRequest request, {
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
  }) async {
    requests.insert(0, request);
    notifyListeners();

    if (request.serviceId == null || request.description == null) {
      // Nothing to send the server without these — keep it local-only.
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

      // Replace the optimistic local entry with the server-confirmed one
      // (which now has a real numeric id) so cancel/update calls work.
      final confirmed = ServiceRequest.fromJson(result);
      final index = requests.indexOf(request);
      if (index != -1) {
        requests[index] = confirmed;
        notifyListeners();
      }
    } catch (_) {
      // Server unreachable — request is still shown locally, just without
      // a real id yet. It'll be replaced on the next successful loadRequests().
    }
  }

  /// Optimistically marks the request as cancelled, then tells the server.
  /// Requires the request to already have a real server [ServiceRequest.id]
  /// (i.e. it must have been successfully submitted/loaded first) — a
  /// purely local/unconfirmed request has nothing to cancel server-side.
  Future<void> cancelRequest(String refNo) async {
    final index = requests.indexWhere((r) => r.refNo == refNo);
    if (index == -1) return;

    final current = requests[index];
    if (current.status == ReqStatus.cancelled ||
        current.status == ReqStatus.completed) return;

    requests[index] = current.copyWith(
      status:      ReqStatus.cancelled,
      cancellable: false,
      note:        'You cancelled this request.',
      timeline: [
        ...current.timeline.where((s) => s.state == RequestStepState.done),
        const TimelineStep(
            'Request cancelled', 'Just now', RequestStepState.done),
      ],
    );
    notifyListeners();

    if (current.id == null) {
      // Never made it to the server — nothing to cancel remotely.
      return;
    }

    try {
      await _api.cancelRequest(current.id!);
    } catch (_) {
      // Server unreachable — status already updated locally.
    }
  }

  /// The most recent active request (under review or scheduled), or null
  /// if none exist.
  ServiceRequest? get activeRequest {
    if (requests.isEmpty) return null;
    return requests.firstWhere(
      (r) => r.status == ReqStatus.review || r.status == ReqStatus.scheduled,
      orElse: () => requests.first,
    );
  }
}