import 'package:flutter/foundation.dart';
import '../models/request_models.dart';
import 'api_service.dart';

/// The two languages the Safety Library content is available in.
/// Add more here (and corresponding content in `library_articles.dart`) to
/// support additional languages.
enum AppLanguage { english, filipino }

extension AppLanguageX on AppLanguage {
  String get label => switch (this) {
        AppLanguage.english => 'English',
        AppLanguage.filipino => 'Filipino',
      };
}

/// App state shared between Home, Services, Track, Library, and Profile.
///
/// Holds the list of submitted service requests and the selected display
/// language for Library content. Requests are now backed by the Laravel
/// API via [ApiService] — [loadRequests] fetches them from the server, and
/// [addRequest] / [cancelRequest] update the local list immediately
/// (optimistic UI) and then sync the change to the server in the
/// background.
class AppState extends ChangeNotifier {
  final ApiService _api;

  AppState(this._api);

  /// Starts empty until [loadRequests] is called (e.g. after login).
  final List<ServiceRequest> requests = [];

  AppLanguage language = AppLanguage.english;

  void setLanguage(AppLanguage value) {
    if (language == value) return;
    language = value;
    notifyListeners();
  }

  int _refCounter = 101;

  /// Generates a new, unique-looking reference number for a freshly
  /// submitted request, e.g. "QR-2026-101".
  String nextRefNo() {
    final ref = 'QR-2026-${_refCounter.toString().padLeft(3, '0')}';
    _refCounter++;
    return ref;
  }

  /// Fetches the resident's requests from the server and replaces the
  /// local list. Call this after login and whenever you need to refresh
  /// (e.g. pull-to-refresh on the Track screen).
  Future<void> loadRequests() async {
    final list = await _api.getRequests();
    requests
      ..clear()
      ..addAll(list.cast<ServiceRequest>());
    notifyListeners();
  }

  /// Adds a newly submitted request to the top of the list right away so
  /// it appears first in Track and becomes the "Active Service Request" on
  /// Home, then sends it to the server in the background.
  Future<void> addRequest(ServiceRequest request) async {
    requests.insert(0, request);
    notifyListeners();

    await _api.submitRequest(
      type: request.type.name,
      refNo: request.refNo,
      metaLines: request.metaLines,
    );
  }

  /// Marks the request with [refNo] as cancelled locally, then tells the
  /// server. Has no effect if the request can't be found or is already in
  /// a final state.
  Future<void> cancelRequest(String refNo) async {
    final index = requests.indexWhere((r) => r.refNo == refNo);
    if (index == -1) return;

    final current = requests[index];
    if (current.status == ReqStatus.cancelled || current.status == ReqStatus.completed) {
      return;
    }

    requests[index] = current.copyWith(
      status: ReqStatus.cancelled,
      cancellable: false,
      note: 'You cancelled this request.',
      timeline: [
        ...current.timeline.where((s) => s.state == RequestStepState.done),
        const TimelineStep('Request cancelled', 'Just now', RequestStepState.done),
      ],
    );
    notifyListeners();

    await _api.cancelRequest(refNo);
  }

  /// The request shown as "Active Service Request" on Home — the most
  /// recently submitted request that's still under review or scheduled,
  /// falling back to the most recent request of any status, or null if
  /// nothing has been submitted yet.
  ServiceRequest? get activeRequest {
    if (requests.isEmpty) return null;
    return requests.firstWhere(
      (r) => r.status == ReqStatus.review || r.status == ReqStatus.scheduled,
      orElse: () => requests.first,
    );
  }
}