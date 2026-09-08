
library serbis.state.app_state;

import 'package:flutter/foundation.dart';
import '../models/advisory.dart';
import '../models/borrow_models.dart';
import '../models/info_material.dart';
import '../models/request_models.dart';
import '../models/service_forms.dart' show AmbulanceIntake;
import 'api_service.dart';
import 'app_log.dart';
import 'borrow_cache.dart';
import 'file_opener.dart';
import 'material_cache.dart';
import 'request_cache.dart';

enum AppLanguage { english, filipino }

/// What happened when a resident tapped a published material. The Library
/// reports each case differently: "no viewer installed" and "you are offline"
/// need different things from the resident.
enum MaterialOpenResult {
  /// Opened the copy on this device. The only case that works with no signal.
  openedSaved,

  /// Opened the server's copy in a browser or external viewer.
  openedOnline,

  /// A saved copy exists but nothing on this device can display it.
  noViewer,

  /// Nothing to open: not saved, and the server copy could not be reached.
  unavailable,
}

extension AppLanguageX on AppLanguage {
  String get label {
    if (this == AppLanguage.english) {
      return 'English';
    }
    return 'Filipino';
  }
}

class AppState extends ChangeNotifier {
  /// Log area for the store. The HTTP layer logs the call itself; these lines
  /// record what the app then did with the failure — emptied a list, rolled a
  /// row back, fell through to the cache — which is the part a resident's
  /// description of the bug will be about.
  static const String _logArea = 'requests';

  final ApiService _api;
  final MaterialCache _materialCache;
  final List<ServiceRequest> requests = [];
  final List<ServiceCatalogItem> services = [];

  static const String _borrowLogArea = 'borrowing';

  /// `GET /equipments` — the borrowing catalogue.
  final List<Equipment> equipment = [];
  bool equipmentLoading = false;
  String? equipmentError;

  /// The resident's own borrow requests, newest first once fetched.
  final List<BorrowRequest> borrowRequests = [];
  final BorrowCache _borrowCache;
  bool _borrowRequestsInFlight = false;
  DateTime? _borrowRequestsFetchedAt;
  DateTime? get borrowRequestsFetchedAt => _borrowRequestsFetchedAt;
  bool borrowRequestsFromCache = false;

  /// Separate from [isOffline]: the two lists are fetched from different
  /// screens at different times, and sharing one flag would let a stale
  /// service-request poll clear the borrowing screen's own banner.
  bool borrowIsOffline = false;

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

  /// MDRRMO blasts this resident received, newest first. From
  /// `GET /advisories`.
  final List<Advisory> advisories = [];

  bool advisoriesLoading = false;

  /// Set when the fetch failed. **The notifications sheet must show this rather
  /// than an empty advisory list.** An empty list and an unreachable server look
  /// identical on screen, and the difference is whether the absence of a flood
  /// warning means there is no flood.
  String? advisoriesError;

  final FileOpener _fileOpener;
  final RequestCache _requestCache;

  AppState(
    this._api, {
    MaterialCache? materialCache,
    FileOpener? fileOpener,
    RequestCache? requestCache,
    BorrowCache? borrowCache,
  })  : _materialCache =
            materialCache ?? MaterialCache(download: _api.downloadFile),
        _fileOpener = fileOpener ?? const FileOpener(),
        _requestCache = requestCache ?? RequestCache(),
        _borrowCache = borrowCache ?? BorrowCache();

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
  /// `service.service_name`, while the catalogue resolves its name through the
  /// app's own translation table. `POST`'s 201 embeds no service at all, so
  /// without this a request would sit unlabelled from the moment it was filed.
  ServiceRequest _resolveService(ServiceRequest request) {
    for (final service in services) {
      if (service.id == request.serviceId) {
        return request.copyWith(
          serviceName: service.displayName(language == AppLanguage.filipino),
          // The code too: the icon, colour and type are keyed on it.
          serviceCode: service.code,
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
    } catch (error) {
      // Deliberately not routed to `lastError`: the services screen renders its
      // own inline "couldn't load, retry" panel off an empty list, and a
      // snackbar on top of it would report the same failure twice. A 401 still
      // reaches the shell through ApiService.onUnauthorized.
      //
      // The log line is what makes that silence recoverable. An empty catalogue
      // and a catalogue the server genuinely has no rows for look identical on
      // screen, and the difference decides whether the resident can file
      // anything at all.
      AppLog.error(_logArea, 'load service catalogue', error: error,
          reason: 'catalogue emptied');
      services.clear();
      notifyListeners();
    }
  }

  /// Which Ambulance units GET /ambulance-availability reports free for
  /// `[start, end)`. Advisory only — shown before submit so a resident is not
  /// steered toward an obviously full slot, not a guarantee, since the
  /// server re-checks under a lock at submission regardless.
  ///
  /// Returns `null` when the check itself could not be reached, which the
  /// caller must treat as "could not check" and not as "nothing is free" —
  /// an empty list and a dead network are different facts and must not read
  /// the same on screen. Never routed through `lastError`/[_fail]: this runs
  /// while the resident is still filling in the form, and a snackbar over an
  /// in-progress booking would report a failure nothing has been submitted
  /// against yet.
  Future<List<Map<String, dynamic>>?> checkAmbulanceAvailability(
    DateTime start,
    DateTime end,
  ) async {
    try {
      return await _api.getAmbulanceAvailability(start: start, end: end);
    } catch (error) {
      AppLog.error(_logArea, 'check ambulance availability', error: error,
          reason: 'inline advisory, not surfaced as lastError');
      return null;
    }
  }

  /// Names a freshly filed borrow request against the catalogue, mirroring
  /// [_resolveService]: `POST /borrowings`' 201 returns the row with no
  /// `equipment` relation loaded, so without this an optimistic row would show
  /// no item name until the next `GET /borrowings`.
  BorrowRequest _resolveBorrow(BorrowRequest request) {
    if (request.equipmentName != null && request.equipmentName!.isNotEmpty) {
      return request;
    }
    for (final item in equipment) {
      if (item.id == request.equipmentId) {
        return request.copyWith(equipmentName: item.name);
      }
    }
    return request;
  }

  /// The equipment catalogue a resident can borrow from.
  Future<void> loadEquipment() async {
    // No synchronous notifyListeners() here, unlike loadMaterials/loadAdvisories:
    // this is called from BorrowEquipmentScreen.initState, and a listener
    // rebuilding an ancestor (RootShell) synchronously, before the first
    // `await`, fires mid-build — "setState() or markNeedsBuild() called during
    // build." loadServices()/loadRequests() avoid the same trap the same way.
    equipmentLoading = true;

    try {
      final list = await _api.getEquipments();
      equipment
        ..clear()
        ..addAll(list.map(Equipment.fromJson));
      equipmentError = null;

      // A row submitted before the catalogue loaded has no name yet.
      for (var i = 0; i < borrowRequests.length; i++) {
        borrowRequests[i] = _resolveBorrow(borrowRequests[i]);
      }
    } catch (error) {
      equipment.clear();
      equipmentError = error is ApiException
          ? error.message
          : 'Something went wrong. Please try again.';
      AppLog.error(_borrowLogArea, 'load equipment catalogue', error: error,
          reason: 'catalogue emptied');
    }

    equipmentLoading = false;
    notifyListeners();
  }

  /// Loads the last borrow-requests list the server sent, so an offline launch
  /// has something to show before the first fetch. Mirrors [hydrateRequests].
  Future<void> hydrateBorrowRequests() async {
    final cached = await _borrowCache.load();
    if (cached == null ||
        borrowRequests.isNotEmpty ||
        _borrowRequestsFetchedAt != null) {
      return;
    }

    borrowRequests.addAll(cached.requests.map(_resolveBorrow));
    _borrowRequestsFetchedAt = cached.fetchedAt;
    borrowRequestsFromCache = true;
    notifyListeners();
  }

  /// Drops the cached borrow requests. Called on logout alongside
  /// [clearRequestCache].
  Future<void> clearBorrowCache() async {
    borrowRequests.clear();
    _borrowRequestsFetchedAt = null;
    borrowRequestsFromCache = false;
    borrowIsOffline = false;
    await _borrowCache.clear();
    notifyListeners();
  }

  /// Refetches the resident's borrow requests. Same shape as [loadRequests] —
  /// see there for why each guard exists.
  Future<void> loadBorrowRequests({bool silent = false, Duration? maxAge}) async {
    if (_borrowRequestsInFlight) {
      return;
    }

    if (maxAge != null &&
        _borrowRequestsFetchedAt != null &&
        DateTime.now().difference(_borrowRequestsFetchedAt!) < maxAge) {
      return;
    }

    _borrowRequestsInFlight = true;

    try {
      final list = await _api.getBorrowings();

      // A request submitted seconds ago has no server id yet; carry it across
      // the same way loadRequests carries an in-flight ServiceRequest.
      final pending = borrowRequests.where((item) => item.id == null).toList();

      borrowRequests
        ..clear()
        ..addAll(pending)
        ..addAll(list.map((item) => _resolveBorrow(BorrowRequest.fromJson(item))));

      _borrowRequestsFetchedAt = DateTime.now();
      borrowRequestsFromCache = false;
      borrowIsOffline = false;
      notifyListeners();

      _borrowCache.save(borrowRequests, _borrowRequestsFetchedAt!);
    } catch (e) {
      borrowIsOffline = e is! ApiException || e.isNetwork;

      AppLog.error(_borrowLogArea, 'load borrow requests', error: e,
          reason: silent ? 'background poll' : 'resident-initiated');

      if (!silent) {
        _fail(e);
      } else {
        notifyListeners();
      }
    } finally {
      _borrowRequestsInFlight = false;
    }
  }

  /// Files a new equipment loan. Returns the server-confirmed row, or `null`
  /// if it never reached MDRRMO — callers must not announce success on a
  /// `null`. Mirrors [addRequest]'s optimistic-insert-then-reconcile shape.
  ///
  /// [item] is null when the resident named something the catalogue does not
  /// list, in which case [otherEquipmentText] carries it. Exactly one of the
  /// two is set; see [ApiService.submitBorrowRequest] for why.
  Future<BorrowRequest?> submitBorrowRequest({
    Equipment? item,
    String? otherEquipmentText,
    required int quantity,
    required String purpose,
    String fulfillmentMethod = 'Pickup',
    String? deliveryAddress,
    String borrowerType = 'Resident',
    String? organizationName,
  }) async {
    final optimistic = _resolveBorrow(BorrowRequest(
      equipmentId: item?.id,
      otherEquipmentText: item == null ? otherEquipmentText : null,
      quantity: quantity,
      purpose: purpose,
      status: BorrowStatus.pending,
      createdAt: DateTime.now(),
      equipmentName: item?.name,
    ));

    borrowRequests.insert(0, optimistic);
    notifyListeners();

    try {
      final result = await _api.submitBorrowRequest(
        equipmentId: item?.id,
        otherEquipmentText: item == null ? otherEquipmentText : null,
        quantity: quantity,
        purpose: purpose,
        fulfillmentMethod: fulfillmentMethod,
        deliveryAddress: deliveryAddress,
        borrowerType: borrowerType,
        organizationName: organizationName,
      );

      final confirmed = _resolveBorrow(BorrowRequest.fromJson(result));

      AppLog.info(_borrowLogArea, 'submit borrow request',
          reason: 'accepted as borrowing ${confirmed.id}');

      final index = borrowRequests.indexOf(optimistic);
      if (index != -1) {
        borrowRequests[index] = confirmed;
        notifyListeners();
      }
      return confirmed;
    } catch (e) {
      // The optimistic row never reached the server, so drop it — leaving it
      // in place is what would make a stock-check 422 look like a filed
      // request MDRRMO will never see.
      borrowRequests.remove(optimistic);
      AppLog.error(_borrowLogArea, 'submit borrow request', error: e,
          reason: 'rolled back, not filed');
      _fail(e);
      return null;
    }
  }

  /// Withdraws a borrow request. Returns `true` only once the server has
  /// accepted it, so the caller never announces a cancellation that did not
  /// happen. Same optimistic-then-roll-back shape as [cancelRequest].
  ///
  /// Keyed on the server's `borrow_id`: a row still in flight has a null id
  /// and no record on the server to cancel yet.
  Future<bool> cancelBorrowRequest(int? id) async {
    if (id == null) {
      lastError = 'This request is still being sent. Please wait a moment and try again.';
      notifyListeners();
      return false;
    }

    final index = borrowRequests.indexWhere((item) => item.id == id);
    if (index == -1) {
      return false;
    }

    final current = borrowRequests[index];
    // Mirrors CANCELLABLE_FROM on the backend. A row that is already Released,
    // Returned, Denied or Cancelled has nothing left to withdraw, and the
    // route would answer 422.
    if (!current.status.isCancellable) {
      return false;
    }

    borrowRequests[index] = current.copyWith(status: BorrowStatus.cancelled);
    notifyListeners();

    try {
      await _api.cancelBorrowRequest(id);
      // The optimistic row is what the cache would otherwise keep serving as
      // Pending until the next fetch — see loadBorrowRequests, which writes
      // the cache on every successful load.
      _borrowCache.save(borrowRequests, _borrowRequestsFetchedAt ?? DateTime.now());
      return true;
    } catch (e) {
      // Put the row back: the office still has the request open, and showing
      // it as cancelled would strand the resident with no way to undo.
      final restoreAt = borrowRequests.indexWhere((item) => item.id == id);
      if (restoreAt != -1) {
        borrowRequests[restoreAt] = current;
      }
      AppLog.error(_borrowLogArea, 'cancel borrow request $id', error: e,
          reason: 'rolled back, still open');
      _fail(e);
      return false;
    }
  }

  /// Bytes of the handover photo staff took at [stage] ('release' or
  /// 'return'), or null when there is none. Not cached and not held on the
  /// row: a photograph is heavier than the whole borrow list and is only ever
  /// looked at when the card is on screen.
  Future<List<int>?> borrowPhoto(int borrowId, String stage) =>
      _api.fetchHandoverPhoto(borrowId, stage);

  /// The blasts MDRRMO sent this resident.
  ///
  /// Never routed to `lastError`: this runs on launch, on resume and on every
  /// poll, and a snackbar per interval during an outage would cover the screen
  /// with the one thing the resident cannot act on. The sheet reports it
  /// inline instead, which is also the only place it means anything.
  ///
  /// A failure **keeps whatever was already loaded**. Clearing the list would
  /// turn a lost signal into "no advisories", and this list is read to find out
  /// whether a warning was issued.
  Future<void> loadAdvisories() async {
    advisoriesLoading = true;
    notifyListeners();

    try {
      final list = await _api.getAdvisories();
      final loaded = list
          .map(Advisory.fromJson)
          .where((advisory) => advisory.isReadable)
          .toList()
        // The server sends newest first; sorting here keeps that true whoever
        // is answering, including a cached or proxied response.
        ..sort((a, b) {
          final epoch = DateTime.fromMillisecondsSinceEpoch(0);
          return (b.sentAt ?? epoch).compareTo(a.sentAt ?? epoch);
        });

      advisories
        ..clear()
        ..addAll(loaded);
      advisoriesError = null;
    } catch (error) {
      advisoriesError = error is ApiException
          ? error.message
          : 'Something went wrong. Please try again.';

      // The count, not the messages: how many the resident is still seeing is
      // the diagnostic, and what the agency told them is their business.
      AppLog.error(_logArea, 'load advisories', error: error,
          reason: 'kept ${advisories.length} already loaded');
    }

    advisoriesLoading = false;
    notifyListeners();
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

      // Counts, not titles: how many saved documents the resident fell back to
      // is the diagnostic, and which documents they are is their business.
      AppLog.error(_logArea, 'load materials', error: error,
          reason: 'fell back to ${materials.length} saved');
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

  /// Opens [material], preferring the copy on this device.
  ///
  /// The saved copy comes first because it is the one that survives a dead
  /// network — which is the whole point of having saved it. If it cannot be
  /// displayed (no PDF viewer installed, file gone since the index was read)
  /// the server copy is still worth trying while there is signal, so a missing
  /// viewer is not reported until both routes have failed.
  Future<MaterialOpenResult> openMaterial(InfoMaterial material) async {
    final saved = savedMaterials[material.id];

    if (saved != null && await _fileOpener.openFile(saved.path)) {
      return MaterialOpenResult.openedSaved;
    }

    if (material.url.isNotEmpty && await _fileOpener.openUrl(material.url)) {
      return MaterialOpenResult.openedOnline;
    }

    return saved != null
        ? MaterialOpenResult.noViewer
        : MaterialOpenResult.unavailable;
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

  /// True while a fetch is in flight. One at a time: the poll, the pull, the
  /// tab switch and the resume can all fire within a second of each other, and
  /// two overlapping fetches both `clear()` the same list.
  bool _requestsInFlight = false;

  /// When the list last came back from the server. Drives [maxAge], so
  /// arriving on a tab does not refetch a list that is seconds old.
  DateTime? _requestsFetchedAt;

  DateTime? get requestsFetchedAt => _requestsFetchedAt;

  /// True when the rows on screen came off the device rather than the server
  /// this launch. Cleared by the first successful fetch.
  bool requestsFromCache = false;

  /// True when the last attempt to reach MDRRMO got no response at all.
  ///
  /// Derived from real request outcomes, not from the OS connectivity flag. A
  /// phone showing full bars on a congested tower is exactly the case this app
  /// exists for, and `connectivity_plus` reports that phone as online; a
  /// request that timed out is evidence, a radio link is not. The cost is that
  /// this cannot know the network is gone until something has tried — so the
  /// banner appears on the first failed poll rather than the instant the signal
  /// drops.
  bool isOffline = false;

  /// Loads the last list the server sent, so an offline launch has something to
  /// show before — and if need be instead of — the first fetch.
  ///
  /// Never overwrites rows already on screen: a fetch that lands first wins,
  /// because it is newer by definition.
  Future<void> hydrateRequests() async {
    final cached = await _requestCache.load();
    if (cached == null || requests.isNotEmpty || _requestsFetchedAt != null) {
      return;
    }

    requests.addAll(cached.requests.map(_resolveService));
    _requestsFetchedAt = cached.fetchedAt;
    requestsFromCache = true;
    notifyListeners();
  }

  /// Drops the cached rows. Called on logout: the next resident to use this
  /// phone must not open the app onto someone else's requests.
  Future<void> clearRequestCache() async {
    requests.clear();
    _requestsFetchedAt = null;
    requestsFromCache = false;
    isOffline = false;
    // The log lines name this resident's own request ids and what they tried to
    // do. They go out with the rows for the same reason the rows do — the next
    // person to use this phone must not be handed either.
    AppLog.clear();
    await _requestCache.clear();
    notifyListeners();
  }

  /// Refetches the resident's requests.
  ///
  /// The dispatcher can move a request from Pending to Responding to Resolved
  /// while the app is open, and this used to run exactly once per launch, so
  /// the resident watched "Under review" until they killed the process.
  ///
  /// [silent] suppresses the error: a background poll that fails must not put
  /// a snackbar over the screen every 45 seconds, and the list already on
  /// screen stays there. A pull-to-refresh is not silent — the resident asked,
  /// so they get an answer.
  ///
  /// [maxAge] skips the fetch entirely when the list is younger than it, so
  /// tapping between Home and Track does not hit the server each time.
  Future<void> loadRequests({bool silent = false, Duration? maxAge}) async {
    if (_requestsInFlight) {
      return;
    }

    if (maxAge != null &&
        _requestsFetchedAt != null &&
        DateTime.now().difference(_requestsFetchedAt!) < maxAge) {
      return;
    }

    _requestsInFlight = true;

    try {
      final list = await _api.getRequests();

      // A request submitted seconds ago has no server id yet, and the server
      // does not know about it, so a refetch would wipe it off the screen —
      // and `addRequest` holds a reference to that exact object to swap for the
      // confirmed row, which it would then never find. Carry them across.
      final pending = requests.where((item) => item.id == null).toList();

      requests
        ..clear()
        ..addAll(pending)
        ..addAll(list.map((item) => _resolveService(ServiceRequest.fromJson(item))));

      _requestsFetchedAt = DateTime.now();
      requestsFromCache = false;
      isOffline = false;
      notifyListeners();

      // Not awaited: the screen is already correct, and a slow write must not
      // hold up the refresh that produced it.
      _requestCache.save(requests, _requestsFetchedAt!);
    } catch (e) {
      // A network failure is the offline case; a 401 or a 500 is the server
      // answering, which means the connection is fine and the banner would be
      // a lie.
      isOffline = e is! ApiException || e.isNetwork;

      // `silent` suppresses the snackbar, never the log — a poll failing every
      // 45 seconds with nothing on screen is precisely the condition that
      // produces an unreproducible report.
      AppLog.error(_logArea, 'load requests', error: e,
          reason: silent ? 'background poll' : 'resident-initiated');

      if (!silent) {
        _fail(e);
      } else {
        // Silent only means no snackbar. The banner still has to appear, and
        // that needs a rebuild.
        notifyListeners();
      }
    } finally {
      _requestsInFlight = false;
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
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    /// Present only for an ambulance request. When it is, the server composes
    /// `description` from it and `request.description` is not sent at all —
    /// the optimistic row still carries its own copy for the Track screen.
    AmbulanceIntake? intake,
  }) async {
    // request.scheduledAt, if any, rides along on `request` itself — the
    // optimistic row already carries it, and it is read off there below
    // rather than repeated as a separate parameter.
    requests.insert(0, request);
    notifyListeners();

    if (request.serviceId == null || request.description == null) {
      requests.remove(request);
      lastError = 'This request is incomplete. Please choose a service and try again.';
      // Never reachable from the form, which validates both. If this line ever
      // shows up in a report, the form and the model have drifted apart.
      AppLog.error(_logArea, 'submit request',
          reason: 'blocked before sending: incomplete');
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
        sitePhotoBytes: sitePhotoBytes,
        sitePhotoFileName: sitePhotoFileName,
        scheduledAt: request.scheduledAt,
        intake: intake,
      );

      final confirmed = _resolveService(ServiceRequest.fromJson(result));

      // The one line most worth having. "I filed a request and MDRRMO says
      // there is no record" is the report this app cannot afford to be unable
      // to answer, and the server's own id is what settles it. The id is the
      // resident's own, and the log never leaves their device unless they send
      // it.
      AppLog.info(_logArea, 'submit request',
          reason: 'accepted as request ${confirmed.id}');

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
      AppLog.error(_logArea, 'submit request', error: e,
          reason: 'rolled back, not filed');
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
    // A disapproved request is closed too: the MDRRMO already refused it, so
    // there is nothing left for the resident to withdraw.
    if (current.status == ReqStatus.cancelled ||
        current.status == ReqStatus.completed ||
        current.status == ReqStatus.disapproved) {
      return false;
    }

    requests[index] = current.copyWith(
      status: ReqStatus.cancelled,
      cancellable: false,
      note: 'You cancelled this request.',
      // The timeline reads its last step off status and updatedAt, so stamping
      // the cancellation time is all it takes; the step no longer has to be
      // assembled by hand here and cannot disagree with the status.
      updatedAt: DateTime.now(),
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
      AppLog.error(_logArea, 'cancel request $id', error: e,
          reason: 'rolled back, still open');
      _fail(e);
      return false;
    }
  }

  ServiceRequest? get activeRequest {
    if (requests.isEmpty) {
      return null;
    }

    for (final item in requests) {
      if (item.status == ReqStatus.review ||
          item.status == ReqStatus.booked ||
          item.status == ReqStatus.scheduled) {
        return item;
      }
    }

    return requests.first;
  }
}