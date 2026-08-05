
library serbis.screens.services;

import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart' as fp;
import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/service_form_fields.dart';
import '../widgets/service_widgets.dart';
import '../widgets/shared_widgets.dart';

/// Picking a service and filing it. Everything this screen used to draw itself
/// — the fields, the dropdowns, the tiles, the safety notice, the ID picker,
/// the error card and the confirmation sheet — now lives in `widgets/`, and
/// the four forms' data lives in `models/service_forms.dart`. What is left is
/// the three jobs only this screen can do: fetch the catalogue, track the
/// selection, and submit.
class ServicesScreen extends StatefulWidget {
  final AppState appState;
  final ServiceType initialType;
  final VoidCallback onSubmitted;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const ServicesScreen({
    super.key,
    required this.appState,
    this.initialType = ServiceType.ambulance,
    required this.onSubmitted,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  List<ServiceCatalogItem> _services = [];
  bool _loadingServices = true;
  ServiceCatalogItem? _selected;

  /// One form per kind, kept for the life of the screen: switching services and
  /// switching back must not silently empty what the resident already typed.
  final Map<ServiceFormKind, ServiceFormData> _forms = {};

  fp.PlatformFile? _validIdFile;

  /// Optional. The backend note calls this the road-clearing form's upload, but
  /// it is offered on every service: a blocked driveway matters to an ambulance
  /// dispatch as much as to a clearing crew, and a rule about which forms may
  /// carry a photo is one the resident would have to discover by its absence.
  fp.PlatformFile? _sitePhotoFile;

  /// True after a submit that never reached the server. Drives a persistent
  /// error card with Retry — a snackbar alone auto-dismisses, and the previous
  /// code showed a success sheet instead.
  bool _submitFailed = false;

  /// True while the multipart POST is in flight, so the button can show a
  /// spinner and refuse repeat taps.
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _loadServices();
  }

  Future<void> _loadServices() async {
    await widget.appState.loadServices();
    if (!mounted) return;
    setState(() {
      // A copy, not the store's list. `AppState.loadServices` mutates that list
      // in place (`..clear()..addAll()`), so aliasing it let a later reload —
      // or a failed one, which clears it — rewrite the grid with no `setState`
      // while `_selected` still pointed at a row that had been removed.
      _services = List.of(widget.appState.services);
      _loadingServices = false;
      _selected = _defaultSelection(_services, widget.initialType);
    });
  }

  ServiceCatalogItem? _defaultSelection(
    List<ServiceCatalogItem> items,
    ServiceType hint,
  ) {
    if (items.isEmpty) return null;
    final hintKind = _kindForType(hint);
    for (final s in items) {
      if (s.formKind == hintKind) return s;
    }
    return items.first;
  }

  // Maps a dashboard shortcut's ServiceType hint onto a real catalogue service,
  // so navigating in from "Ambulance" still preselects a medical service.
  ServiceFormKind _kindForType(ServiceType t) {
    switch (t) {
      case ServiceType.ambulance:
      case ServiceType.transfer:
        return ServiceFormKind.ambulance;
      case ServiceType.road:
        return ServiceFormKind.road;
      case ServiceType.relief:
        return ServiceFormKind.relief;
      case ServiceType.inquiry:
      case ServiceType.items:
        return ServiceFormKind.generic;
    }
  }

  ServiceType _typeForKind(ServiceFormKind kind) {
    switch (kind) {
      case ServiceFormKind.ambulance:
        return ServiceType.ambulance;
      case ServiceFormKind.road:
        return ServiceType.road;
      case ServiceFormKind.relief:
        return ServiceType.relief;
      case ServiceFormKind.generic:
        return ServiceType.inquiry;
    }
  }

  ServiceFormData _formFor(ServiceFormKind kind) =>
      _forms.putIfAbsent(kind, () => switch (kind) {
            ServiceFormKind.ambulance => AmbulanceFormData(),
            ServiceFormKind.road => RoadFormData(),
            ServiceFormKind.relief => ReliefFormData(),
            ServiceFormKind.generic => GenericFormData(),
          });

  @override
  void didUpdateWidget(covariant ServicesScreen old) {
    super.didUpdateWidget(old);
    if (old.initialType != widget.initialType && _services.isNotEmpty) {
      setState(() => _selected = _defaultSelection(_services, widget.initialType));
    }
  }

  @override
  void dispose() {
    for (final form in _forms.values) {
      form.dispose();
    }
    super.dispose();
  }

  /// [_selected] is captured when the tile is tapped, so it goes stale the
  /// moment the catalogue is refetched in another language. Re-resolving by id
  /// keeps the form header in step with the tile above it.
  ServiceCatalogItem? get _currentSelection {
    final selected = _selected;
    if (selected == null) {
      return null;
    }
    for (final service in _services) {
      if (service.id == selected.id) {
        return service;
      }
    }
    return selected;
  }

  /// The submission time as it goes into the description. Always English and
  /// always the device's local clock: this string is read by a dispatcher in
  /// the admin panel, not by the resident.
  String _nowLabel() => formatTimelineTime(DateTime.now(), false);

  Future<void> _pickValidId() async {
    final picked = await _pickImage();
    if (picked != null) {
      setState(() => _validIdFile = picked);
    }
  }

  Future<void> _pickSitePhoto() async {
    final picked = await _pickImage();
    if (picked != null) {
      setState(() => _sitePhotoFile = picked);
    }
  }

  Future<fp.PlatformFile?> _pickImage() async {
    final result = await fp.FilePicker.platform.pickFiles(
      type: fp.FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png'],
      withData: true, // ensures .bytes is populated (needed on web)
    );
    if (result == null || result.files.isEmpty) {
      return null;
    }
    return result.files.first;
  }

  Future<void> _submit() async {
    // The multipart upload carries a photo and has a 30-second timeout. Without
    // this guard every extra tap in that window filed another live request in
    // the dispatcher's queue.
    if (_submitting) {
      return;
    }

    if (_validIdFile == null || _validIdFile!.bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please attach a photo of your valid ID before submitting.')),
      );
      return;
    }

    final service = _selected;
    if (service == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please choose a service before submitting.')),
      );
      return;
    }

    final form = _formFor(service.formKind);

    // A dispatcher who cannot call back cannot dispatch. Enforced here rather
    // than in the field widget because these are plain TextFields, not a Form.
    final contact = form.requiredContactNumber;
    if (contact != null && contact.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a contact number so MDRRMO can reach you.')),
      );
      return;
    }

    final metaLines = form.metaLines(
      serviceName: service.name,
      submittedLabel: _nowLabel(),
    );

    final request = ServiceRequest(
      serviceId: service.id,
      description: metaLines.join('\n'),
      type: _typeForKind(service.formKind),
      // Empty until the server answers: the reference number is the server's
      // request_id, and inventing one locally gave the resident a number that
      // matched no record in tbl_service_request.
      refNo: '',
      status: ReqStatus.review,
      cancellable: true,
      metaLines: metaLines,
      // The server's created_at replaces this the moment the row comes back;
      // until then the timeline still has a real submission time to show.
      createdAt: DateTime.now(),
    );

    setState(() => _submitting = true);

    ServiceRequest? confirmed;
    try {
      confirmed = await widget.appState.addRequest(
        request,
        validIdFileBytes: _validIdFile!.bytes!,
        validIdFileName: _validIdFile!.name,
        // `bytes` is null when the picker returns a path-only file, which is
        // what happens if `withData` ever stops holding. Sending the name
        // without the bytes would be a 422 on an upload the resident is not
        // required to make at all.
        sitePhotoBytes: _sitePhotoFile?.bytes,
        sitePhotoFileName: _sitePhotoFile?.bytes == null ? null : _sitePhotoFile?.name,
      );
    } finally {
      if (mounted) {
        setState(() => _submitting = false);
      } else {
        _submitting = false;
      }
    }

    if (!mounted) return;

    // The submit failed and the optimistic row has already been rolled back.
    // Keep every entered value and the attached photo so Retry costs one tap,
    // and show nothing that could be read as "help is on the way".
    if (confirmed == null) {
      setState(() => _submitFailed = true);
      return;
    }

    // The ID is kept — it is the same ID next time, and re-picking it is pure
    // friction. The site photo is not: it is a photo of one incident, and
    // leaving it attached would silently file the last emergency's scene with
    // the next request. On the failure path above it stays, because Retry has
    // to cost one tap.
    setState(() {
      _submitFailed = false;
      _sitePhotoFile = null;
    });

    // A mutable local is not promoted inside a closure, and the sheet's builder
    // is one.
    final filed = confirmed;

    final f = widget.appState.language == AppLanguage.filipino;
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => ConfirmationSheet(
        refNo: filed.refNo,
        filipino: f,
        onViewTrack: () {
          Navigator.pop(context);
          widget.onSubmitted();
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;
    final selection = _currentSelection;

    return ListView(
      padding: EdgeInsets.zero,
      children: [
        AppHeader(onNotificationsTap: widget.onOpenNotifications, onProfileTap: widget.onOpenProfile),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SectionHeader(title: tr(f, 'services.title')),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SafetyNotice(filipino: f),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: tr(f, 'services.choose_type')),
              _buildServiceGrid(),
            ],
          ),
        ),
        if (selection != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SectionHeader(title: selection.nameLocalized),
                ServiceFormFields(
                  data: _formFor(selection.formKind),
                  onChanged: () => setState(() {}),
                ),
                AttachmentUploadField(
                  label: 'Valid ID (required)',
                  hint: 'Tap to upload a photo of a valid ID (jpg/png, max 2MB)',
                  fileName: _validIdFile?.name,
                  onTap: _pickValidId,
                ),
                AttachmentUploadField(
                  label: 'Photo of the site (optional)',
                  hint: 'Tap to add a photo of the scene (jpg/png, max 4MB)',
                  fileName: _sitePhotoFile?.name,
                  onTap: _pickSitePhoto,
                  onClear: () => setState(() => _sitePhotoFile = null),
                ),
                if (_submitFailed) SubmitErrorCard(filipino: f, onRetry: _submit),
                const SizedBox(height: 6),
                AppButton(
                  label: tr(f, 'common.submit_request'),
                  onPressed: _submit,
                  loading: _submitting,
                ),
              ],
            ),
          ),
        const SizedBox(height: 110),
      ],
    );
  }

  Widget _buildServiceGrid() {
    if (_loadingServices) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 30),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    if (_services.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 20),
        child: Row(
          children: [
            const Icon(Icons.wifi_off_rounded, size: 18, color: AppColors.inkFaint),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                "Couldn't load services. Check your connection and try again.",
                style: AppText.body(size: 12, color: AppColors.inkMuted),
              ),
            ),
            TextButton(
              onPressed: () {
                setState(() => _loadingServices = true);
                _loadServices();
              },
              child: const Text('Retry'),
            ),
          ],
        ),
      );
    }

    return ServiceGrid(
      count: _services.length,
      cardBuilder: (index) {
        final service = _services[index];
        return ServiceTypeCard(
          title: service.nameLocalized,
          subtitle: service.displayDescription,
          icon: service.icon,
          selected: service.id == _selected?.id,
          onTap: () => setState(() => _selected = service),
        );
      },
    );
  }
}
