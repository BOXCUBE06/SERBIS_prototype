import 'package:flutter/foundation.dart';
import '../models/models.dart';

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

/// Simple in-memory app state shared between Home, Services, Track,
/// Library, and Profile.
///
/// Holds the list of submitted service requests (starting empty — no mock
/// "dead" records) and the selected display language for Library content.
///
/// There is no backend yet. When one is available, replace [requests] with
/// data fetched from your API, and have [addRequest] / [cancelRequest] call
/// the corresponding endpoints (optimistically updating the local list in
/// the meantime is fine).
class AppState extends ChangeNotifier {
  /// Starts empty — residents only see requests they've actually submitted.
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

  /// Adds a newly submitted request to the top of the list so it appears
  /// first in Track and becomes the "Active Service Request" on Home.
  void addRequest(ServiceRequest request) {
    requests.insert(0, request);
    notifyListeners();
  }

  /// Marks the request with [refNo] as cancelled. Has no effect if the
  /// request can't be found or is already in a final state.
  void cancelRequest(String refNo) {
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
