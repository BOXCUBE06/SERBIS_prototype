
library serbis.screens.services;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:file_picker/file_picker.dart' as fp;
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';


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

  final Map<String, TextEditingController> _controllers = {};
  final Map<String, String> _dropdowns = {};

  fp.PlatformFile? _validIdFile;

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

  @override
  void didUpdateWidget(covariant ServicesScreen old) {
    super.didUpdateWidget(old);
    if (old.initialType != widget.initialType && _services.isNotEmpty) {
      setState(() => _selected = _defaultSelection(_services, widget.initialType));
    }
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _ctrl(String key) => _controllers.putIfAbsent(key, () => TextEditingController());

  String _dropdownValue(String key, List<String> items) => _dropdowns[key] ?? items.first;

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

  String _text(String key) => _ctrl(key).text.trim();

  String _orFallback(String value, String fallback) => value.isEmpty ? fallback : value;

  /// The controller key of the contact number a dispatcher must have for this
  /// form, or null where a callback number is not part of the response.
  String? _requiredContactKey(ServiceFormKind kind) {
    switch (kind) {
      case ServiceFormKind.ambulance:
        return 'amb_contact';
      case ServiceFormKind.relief:
        return 'relief_contact';
      case ServiceFormKind.road:
      case ServiceFormKind.generic:
        return null;
    }
  }

  String _nowLabel() {
    final now = DateTime.now();
    final hour12 = now.hour % 12 == 0 ? 12 : now.hour % 12;
    final minute = now.minute.toString().padLeft(2, '0');
    final period = now.hour >= 12 ? 'PM' : 'AM';
    return 'Today, $hour12:$minute $period';
  }

  Future<void> _pickValidId() async {
    final result = await fp.FilePicker.platform.pickFiles(
      type: fp.FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png'],
      withData: true, // ensures .bytes is populated (needed on web)
    );
    if (result != null && result.files.isNotEmpty) {
      setState(() => _validIdFile = result.files.first);
    }
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

    // A dispatcher who cannot call back cannot dispatch. Enforced here rather
    // than in _Field because these are plain TextFields, not a Form.
    final contactKey = _requiredContactKey(service.formKind);
    if (contactKey != null && _text(contactKey).isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a contact number so MDRRMO can reach you.')),
      );
      return;
    }

    final timeLabel = _nowLabel();

    late List<String> metaLines;
    switch (service.formKind) {
      case ServiceFormKind.ambulance:
        final patient = _orFallback(_text('amb_patient'), 'Not specified');
        final pickup = _orFallback(_text('amb_pickup'), 'Pick-up location not specified');
        final destination = _orFallback(_text('amb_destination'), 'destination not specified');
        metaLines = [
          service.name,
          'Patient: $patient',
          '$pickup → $destination',
          'Condition: ${_orFallback(_text('amb_notes'), 'Not described')}',
          'Contact: ${_text('amb_contact')}',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceFormKind.road:
        final location = _orFallback(_text('road_location'), 'Location not specified');
        final obstruction = _dropdownValue('road_obstruction', _obstructionTypes);
        metaLines = [
          service.name,
          location,
          'Obstruction: $obstruction',
          'Description: ${_orFallback(_text('road_description'), 'No description provided')}',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceFormKind.relief:
        final head = _orFallback(_text('relief_head'), 'Not specified');
        final address = _orFallback(_text('relief_address'), 'Address not specified');
        final assistance = _dropdownValue('relief_type', _assistanceTypes);
        metaLines = [
          service.name,
          'Household head: $head',
          address,
          'Household size: ${_orFallback(_text('relief_size'), 'Not specified')}',
          'Assistance: $assistance',
          'Contact: ${_text('relief_contact')}',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceFormKind.generic:
        final details = _orFallback(_text('gen_details'), 'No details provided');
        final contact = _orFallback(_text('gen_contact'), 'Not specified');
        metaLines = [
          service.name,
          details,
          'Contact: $contact',
          'Submitted $timeLabel',
        ];
        break;
    }
    final description = metaLines.join('\n');

    final request = ServiceRequest(
      serviceId: service.id,
      description: description,
      type: _typeForKind(service.formKind),
      // Empty until the server answers: the reference number is the server's
      // request_id, and inventing one locally gave the resident a number that
      // matched no record in tbl_service_request.
      refNo: '',
      status: ReqStatus.review,
      cancellable: true,
      metaLines: metaLines,
      timeline: [
        TimelineStep('Request submitted', timeLabel, RequestStepState.done),
        const TimelineStep('Forwarded to MDRRMO for review', 'Pending', RequestStepState.pending),
        const TimelineStep('Completed', 'Pending', RequestStepState.pending),
      ],
    );

    setState(() => _submitting = true);

    ServiceRequest? confirmed;
    try {
      confirmed = await widget.appState.addRequest(
        request,
        validIdFileBytes: _validIdFile!.bytes!,
        validIdFileName: _validIdFile!.name,
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

    setState(() => _submitFailed = false);

    // A mutable local is not promoted inside a closure, and the sheet's builder
    // is one.
    final filed = confirmed;

    final f = widget.appState.language == AppLanguage.filipino;
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _ConfirmationSheet(
        refNo: filed.refNo,
        filipino: f,
        onViewTrack: () {
          Navigator.pop(context);
          widget.onSubmitted();
        },
      ),
    );
  }

  static const _obstructionTypes = ['Fallen tree / branches', 'Flooding / silt', 'Landslide debris', 'Other'];
  static const _assistanceTypes = ['Food packs', 'Hygiene kits', 'Drinking water', 'Temporary shelter materials', 'Other'];

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;
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
          child: _SafetyNotice(filipino: f),
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
        if (_currentSelection != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SectionHeader(title: _currentSelection!.nameLocalized),
                _buildForm(_currentSelection!.formKind),
                _ValidIdUploadField(
                  fileName: _validIdFile?.name,
                  onTap: _pickValidId,
                ),
                if (_submitFailed) _SubmitErrorCard(filipino: f, onRetry: _submit),
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
    // Rows of two rather than a GridView: childAspectRatio pinned every tile to
    // one height, so the longest label decided what got clipped. Here the row
    // is as tall as its taller tile and no taller, and IntrinsicHeight keeps the
    // pair matched so the grid still reads as a grid.
    final rows = <Widget>[];
    for (var i = 0; i < _services.length; i += 2) {
      final left = _services[i];
      final right = i + 1 < _services.length ? _services[i + 1] : null;

      rows.add(Padding(
        padding: EdgeInsets.only(bottom: i + 2 < _services.length ? 10 : 0),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: _serviceCard(left)),
              const SizedBox(width: 10),
              // An odd-length catalogue leaves a hole rather than a
              // double-width tile.
              Expanded(child: right == null ? const SizedBox.shrink() : _serviceCard(right)),
            ],
          ),
        ),
      ));
    }

    return Column(children: rows);
  }

  Widget _serviceCard(ServiceCatalogItem service) {
    return _TypeCard(
      title: service.nameLocalized,
      subtitle: service.displayDescription,
      icon: service.icon,
      selected: service.id == _selected?.id,
      onTap: () => setState(() => _selected = service),
    );
  }

  Widget _buildForm(ServiceFormKind kind) {
    switch (kind) {
      case ServiceFormKind.ambulance:
        return Column(children: [
          _Field(label: 'Patient name', hint: 'e.g. Maria Santos', controller: _ctrl('amb_patient')),
          _Field(label: 'Pick-up location', hint: 'Purok / street, barangay', controller: _ctrl('amb_pickup')),
          _Field(label: 'Destination', hint: 'e.g. Echague District Hospital', controller: _ctrl('amb_destination')),
          _Field(
            label: 'Condition / notes',
            hint: "Briefly describe the patient's condition",
            lines: 3,
            controller: _ctrl('amb_notes'),
          ),
          _Field(
            label: 'Contact number',
            hint: '09XXXXXXXXX',
            keyboard: TextInputType.phone,
            maxLength: 11,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            controller: _ctrl('amb_contact'),
          ),
        ]);
      case ServiceFormKind.road:
        return Column(children: [
          _Field(
            label: 'Location / road name',
            hint: 'e.g. Brgy. Malasin – Provincial Road',
            controller: _ctrl('road_location'),
          ),
          _Dropdown(
            label: 'Obstruction type',
            items: _obstructionTypes,
            value: _dropdownValue('road_obstruction', _obstructionTypes),
            onChanged: (v) => setState(() => _dropdowns['road_obstruction'] = v),
          ),
          _Field(
            label: 'Description',
            hint: "Describe the obstruction and how it's affecting access",
            lines: 3,
            controller: _ctrl('road_description'),
          ),
          const _UploadField(label: 'Attach photo (optional)'),
        ]);
      case ServiceFormKind.relief:
        return Column(children: [
          _Field(label: 'Household head name', hint: 'Full name', controller: _ctrl('relief_head')),
          _Field(label: 'Address', hint: 'Purok / street, barangay', controller: _ctrl('relief_address')),
          Row(
            children: [
              Expanded(
                child: _Field(
                  label: 'Household size',
                  hint: 'e.g. 5',
                  keyboard: TextInputType.number,
                  controller: _ctrl('relief_size'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _Field(
                  label: 'Contact number',
                  hint: '09XXXXXXXXX',
                  keyboard: TextInputType.phone,
                  maxLength: 11,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  controller: _ctrl('relief_contact'),
                ),
              ),
            ],
          ),
          _Dropdown(
            label: 'Type of assistance needed',
            items: _assistanceTypes,
            value: _dropdownValue('relief_type', _assistanceTypes),
            onChanged: (v) => setState(() => _dropdowns['relief_type'] = v),
          ),
        ]);
      case ServiceFormKind.generic:
        return Column(children: [
          _Field(
            label: 'Details',
            hint: 'Describe what you need and where',
            lines: 4,
            controller: _ctrl('gen_details'),
          ),
          _Field(
            label: 'Contact number',
            hint: '09XXXXXXXXX',
            keyboard: TextInputType.phone,
            maxLength: 11,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            controller: _ctrl('gen_contact'),
          ),
        ]);
    }
  }
}

class _ValidIdUploadField extends StatelessWidget {
  final String? fileName;
  final VoidCallback onTap;

  const _ValidIdUploadField({required this.fileName, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final hasFile = fileName != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Valid ID (required)', style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(10),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 14),
              decoration: BoxDecoration(
                color: hasFile ? AppColors.green50 : AppColors.surface,
                border: Border.all(color: hasFile ? AppColors.green700 : AppColors.line, width: 1.5),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  Icon(
                    hasFile ? Icons.check_circle_rounded : Icons.cloud_upload_outlined,
                    color: hasFile ? AppColors.green700 : AppColors.inkFaint,
                    size: 20,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      hasFile ? fileName! : 'Tap to upload a photo of a valid ID (jpg/png, max 2MB)',
                      style: AppText.body(size: 12, color: hasFile ? AppColors.green900 : AppColors.inkMuted),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SafetyNotice extends StatelessWidget {
  final bool filipino;
  const _SafetyNotice({required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.red50,
        border: Border.all(color: const Color(0xFFF4D9D2)),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconBadge(icon: Icons.warning_amber_rounded, bg: AppColors.surface, fg: AppColors.red600),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  tr(f, 'services.notice_title'),
                  style: AppText.display(size: 13, weight: FontWeight.w700, color: AppColors.red600),
                ),
                const SizedBox(height: 4),
                Text(
                  tr(f, 'services.notice_body'),
                  style: AppText.body(size: 12, color: const Color(0xFF7A3527), height: 1.6),
                ),
                const SizedBox(height: 8),
                _hotlineLine('MDRRMO — 0917-123-4567'),
                _hotlineLine(f ? 'Pulis (PNP) — 117' : 'Police (PNP) — 117'),
                _hotlineLine(f ? 'Bumbero (BFP) — 116' : 'Fire (BFP) — 116'),
                _hotlineLine(f ? 'Pambansang Emerhensiya — 911' : 'National Emergency — 911'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _hotlineLine(String text) => Padding(
        padding: const EdgeInsets.only(top: 2),
        child: Text(text, style: AppText.display(size: 12, weight: FontWeight.w700, color: AppColors.red600)),
      );
}

class _TypeCard extends StatelessWidget {
  final String title;
  final String subtitle;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  const _TypeCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? AppColors.green50 : AppColors.surface,
          border: Border.all(color: selected ? AppColors.green700 : AppColors.line, width: 1.5),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            IconBadge(
              icon: icon,
              bg: selected ? AppColors.green50 : AppColors.paper,
              fg: selected ? AppColors.green700 : AppColors.inkMuted,
              size: 34,
              iconSize: 16,
              radius: 10,
            ),
            const SizedBox(height: 8),
            Text(
              title,
              style: AppText.display(size: 12, weight: FontWeight.w600, height: 1.25),
            ),
            if (subtitle.isNotEmpty) ...[
              const SizedBox(height: 2),
              // No maxLines and no ellipsis on purpose. Capping at one line cut
              // every Tagalog blurb mid-word, and capping at two still truncates
              // the longest of them at 360 px. Yogad is coming and will be
              // longer again, so the tile grows to the text rather than the text
              // being cut to the tile.
              Text(
                subtitle,
                style: AppText.body(size: 10.5, color: AppColors.inkMuted),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  final String label;
  final String hint;
  final int lines;
  final TextInputType keyboard;
  final TextEditingController controller;
  final int? maxLength;
  final List<TextInputFormatter>? inputFormatters;

  const _Field({
    required this.label,
    required this.hint,
    required this.controller,
    this.lines = 1,
    this.keyboard = TextInputType.text,
    this.maxLength,
    this.inputFormatters,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextField(
            controller: controller,
            maxLines: lines,
            keyboardType: keyboard,
            maxLength: maxLength,
            inputFormatters: inputFormatters,
            style: AppText.body(size: 13),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle: AppText.body(size: 13, color: AppColors.inkFaint),
              counterText: '',
              filled: true,
              fillColor: AppColors.surface,
              contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.green600, width: 1.5),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Dropdown extends StatelessWidget {
  final String label;
  final List<String> items;
  final String value;
  final ValueChanged<String> onChanged;

  const _Dropdown({
    required this.label,
    required this.items,
    required this.value,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 13),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.line, width: 1.5),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<String>(
                value: value,
                isExpanded: true,
                icon: const Icon(Icons.expand_more_rounded, color: AppColors.inkFaint),
                style: AppText.body(size: 13, color: AppColors.ink),
                items: items.map((i) => DropdownMenuItem(value: i, child: Text(i))).toList(),
                onChanged: (v) {
                  if (v != null) onChanged(v);
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _UploadField extends StatelessWidget {
  final String label;
  const _UploadField({required this.label});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 22),
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.line, width: 1.5),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              children: [
                const Icon(Icons.cloud_upload_outlined, color: AppColors.inkFaint, size: 22),
                const SizedBox(height: 6),
                Text('Tap to upload a photo of the site', style: AppText.body(size: 12, color: AppColors.inkMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Shown in place of the confirmation sheet when the submit failed. Stays on
/// screen — unlike the error snackbar the shell drains — because the resident
/// has to know the request was never filed.
class _SubmitErrorCard extends StatelessWidget {
  final bool filipino;
  final VoidCallback onRetry;

  const _SubmitErrorCard({required this.filipino, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.red50,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.error_outline_rounded, size: 18, color: AppColors.red600),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  filipino
                      ? 'Hindi naipadala ang kahilingan. Nandito pa ang mga detalye mo — subukang muli.'
                      : "Your request wasn't sent. Your details are still here — tap Retry to send them again.",
                  style: AppText.body(size: 12, color: AppColors.red600, height: 1.5),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          AppButton(
            label: filipino ? 'Subukang muli' : 'Retry',
            style: AppButtonStyle.outline,
            onPressed: onRetry,
          ),
        ],
      ),
    );
  }
}

class _ConfirmationSheet extends StatelessWidget {
  final String refNo;
  final bool filipino;
  final VoidCallback onViewTrack;
  const _ConfirmationSheet({required this.refNo, required this.filipino, required this.onViewTrack});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final body = tr(f, 'services.confirm.body').replaceAll('{ref}', refNo);
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(22, 32, 22, 28),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: const BoxDecoration(color: AppColors.green50, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: const Icon(Icons.check_rounded, size: 26, color: AppColors.green700),
          ),
          const SizedBox(height: 14),
          Text(tr(f, 'services.confirm.title'), style: AppText.display(size: 17)),
          const SizedBox(height: 6),
          Text(
            body,
            textAlign: TextAlign.center,
            style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.6),
          ),
          const SizedBox(height: 18),
          AppButton(label: tr(f, 'services.confirm.view_track'), onPressed: onViewTrack),
        ],
      ),
    );
  }
}