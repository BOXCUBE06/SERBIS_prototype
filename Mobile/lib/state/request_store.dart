
library serbis.state.app_state;

import 'package:flutter/foundation.dart';
import '../models/info_material.dart';
import '../models/request_models.dart';
import 'api_service.dart';
import 'material_cache.dart';

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
  final MaterialCache _materialCache;
  final List<ServiceRequest> requests = [];
  final List<ServiceCatalogItem> services = [];

  /// Materials published by MDRRMO. Comes from `GET /info-materials`, or from
  /// the offline index when the server cannot be reached.
  final List<InfoMaterial> materials = [];

  /// id -> the copy on this device. Drives the "Saved" state; nothing else may.
  Map<int, CachedMaterial> savedMaterials = <int, CachedMaterial>{};

  /// Downloads in flight, so a second tap cannot start a second download of the
  /// same file.
  final Set<int> savingMaterialIds = <int>{};

  bool materialsLoading = false;
  String? materialsError;

  /// True when the list on screen came from the device, not the server. The
  /// Library says so — a resident reading a week-old advisory during a flood
  /// needs to know it might be stale.
  bool materialsFromCache = false;

  AppState(this._api, {MaterialCache? materialCache})
      : _materialCache =
            materialCache ?? MaterialCache(download: _api.downloadFile);

  /// False on web, where there is nowhere to write. The download affordance is
  /// hidden entirely rather than offered and failing.
  bool get canSaveOffline => _materialCache.isSupported;

  bool isSavedOffline(int id) => savedMaterials.containsKey(id);

  bool isSavingOffline(int id) => savingMaterialIds.contains(id);

  AppLanguage language = AppLanguage.english;

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

  /// The locale sent to the API, derived from the language toggle. The server
  /// falls back to English for a locale it has no rows for, so this is safe to
  /// send even once Yogad is offered in the UI but not yet translated.
  String get locale => language == AppLanguage.filipino ? 'fil' : 'en';

  /// Names a parsed row from the catalogue, which always wins over the name the
  /// row carries: `GET /service-requests` embeds the untranslated
  /// `service.service_name`, while the catalogue was fetched in the resident's
  /// own language. `POST`'s 201 embeds no service at all, so without this a
  /// request would sit unlabelled from the moment it was filed.
  ServiceRequest _resolveService(ServiceRequest request) {
    for (final service in services) {
      if (service.id == request.serviceId) {
        return request.copyWith(
          serviceName: service.nameLocalized,
          // English too: the icon and colour are keyed on it.
          serviceNameEn: service.name,
        );
      }
    }
    // Catalogue not loaded yet: keep whatever the payload named it. The next
    // loadServices() relabels every row.
    return request;
  }

  void _relabelRequests() {
    for (var i = 0; i < requests.length; i++) {
      requests[i] = _resolveService(requests[i]);
    }
  }

  void _fail(Object error) {
    lastError = error is ApiException
        ? error.message
        : 'Something went wrong. Please try again.';
    notifyListeners();
  }

  Future<void> loadServices() async {
    try {
      final list = await _api.getServices(locale: locale);
      services
        ..clear()
        ..addAll(list.map(ServiceCatalogItem.fromJson));
      // Rows already on screen were labelled before this catalogue arrived, or
      // in the previous language.
      _relabelRequests();
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

  /// Loads the offline index first, then refreshes from the server. The index
  /// comes first on purpose: it is instant and works with no signal, so the
  /// Library has content on screen before the network is even tried.
  ///
  /// A failed refresh is not an empty Library. Saved copies are rendered
  /// instead, flagged as such — this is scope item #4, and "no signal" is the
  /// exact condition the feature exists for.
  Future<void> loadMaterials() async {
    materialsLoading = true;
    notifyListeners();

    savedMaterials = await _materialCache.loadIndex();

    try {
      final list = await _api.getInfoMaterials();
      materials
        ..clear()
        ..addAll(list.map(InfoMaterial.fromJson));
      materialsFromCache = false;
      materialsError = null;
    } catch (error) {
      final offline = savedMaterials.values.toList()
        ..sort((a, b) => b.savedAt.compareTo(a.savedAt));
      materials
        ..clear()
        ..addAll(offline.map((entry) => entry.toMaterial()));
      materialsFromCache = materials.isNotEmpty;
      materialsError = error is ApiException
          ? error.message
          : 'Something went wrong. Please try again.';
    }

    materialsLoading = false;
    notifyListeners();
  }

  /// Returns whether the file is now on the device. The caller must not
  /// announce "saved" on a false — announcing it anyway is what made the old
  /// pill a placebo.
  Future<bool> saveMaterialOffline(InfoMaterial material) async {
    if (!canSaveOffline ||
        savingMaterialIds.contains(material.id) ||
        savedMaterials.containsKey(material.id)) {
      return false;
    }

    savingMaterialIds.add(material.id);
    notifyListeners();

    final entry = await _materialCache.save(material);

    savingMaterialIds.remove(material.id);
    if (entry != null) {
      savedMaterials = <int, CachedMaterial>{...savedMaterials, entry.id: entry};
    }
    notifyListeners();

    return entry != null;
  }

  Future<void> removeMaterialOffline(int id) async {
    await _materialCache.remove(id);
    final next = <int, CachedMaterial>{...savedMaterials}..remove(id);
    savedMaterials = next;

    // The row itself only exists because of the saved copy when the list came
    // from the cache; drop it too rather than leave a title pointing at nothing.
    if (materialsFromCache) {
      materials.removeWhere((material) => material.id == id);
    }

    notifyListeners();
  }

  void setLanguage(AppLanguage value) {
    if (language == value) {
      return;
    }

    language = value;
    notifyListeners();

    // Service names live on the server, so switching language means refetching
    // the catalogue; loadServices relabels the open requests when it lands.
    // Not awaited: the rest of the UI translates from local strings immediately
    // and must not wait on the network to do it.
    loadServices();
  }

  Future<void> loadRequests() async {
    try {
      final list = await _api.getRequests();
      requests.clear();
      for (final item in list) {
        requests.add(_resolveService(ServiceRequest.fromJson(item)));
      }
      notifyListeners();
    } catch (e) {
      _fail(e);
    }
  }

  /// Returns the server-confirmed row, or `null` if the request never reached
  /// MDRRMO. Callers must not announce success on a `null` — that is what let a
  /// 422 render a green confirmation sheet with a reference number.
  Future<ServiceRequest?> addRequest(
    ServiceRequest request, {
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
  }) async {
    requests.insert(0, request);
    notifyListeners();

    if (request.serviceId == null || request.description == null) {
      requests.remove(request);
      lastError = 'This request is incomplete. Please choose a service and try again.';
      notifyListeners();
      return null;
    }

    try {
      final result = await _api.submitRequest(
        serviceId: request.serviceId!,
        description: request.description!,
        validIdFileBytes: validIdFileBytes,
        validIdFileName: validIdFileName,
        requiredVehicleType: requiredVehicleType,
      );

      final confirmed = _resolveService(ServiceRequest.fromJson(result));
      final index = requests.indexOf(request);
      if (index != -1) {
        requests[index] = confirmed;
        notifyListeners();
      }
      return confirmed;
    } catch (e) {
      // The optimistic row never reached the server, so drop it. Leaving it in
      // place is what made a 422 "No available vehicles at this time." look like
      // a filed request that MDRRMO would never see.
      requests.remove(request);
      _fail(e);
      return null;
    }
  }

  /// Keyed on the server's `request_id`, never on `refNo`: an unconfirmed row
  /// carries an empty ref, so every in-flight request would match the same key.
  /// Returns `true` only once the server has accepted the cancellation.
  Future<bool> cancelRequest(int? id) async {
    if (id == null) {
      // Still in flight: the server has no record to cancel yet.
      lastError = 'This request is still being sent. Please wait a moment and try again.';
      notifyListeners();
      return false;
    }

    final index = requests.indexWhere((item) => item.id == id);
    if (index == -1) {
      return false;
    }

    final current = requests[index];
    if (current.status == ReqStatus.cancelled ||
        current.status == ReqStatus.completed) {
      return false;
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

    try {
      await _api.cancelRequest(id);
      return true;
    } catch (e) {
      // Put the request back the way it was — the server still has it open, and
      // showing it as cancelled would strand the resident with no way to undo.
      final restoreAt = requests.indexWhere((item) => item.id == id);
      if (restoreAt != -1) {
        requests[restoreAt] = current;
      }
      _fail(e);
      return false;
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