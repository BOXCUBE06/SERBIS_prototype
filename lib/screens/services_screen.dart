import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/models.dart';
import '../state/app_state.dart';
import '../state/strings.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

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
  late ServiceType _selected;

  // All text fields across every service type, keyed by a unique field id.
  // Using one map (rather than per-type state) keeps user input around if
  // they switch between request types and come back.
  final Map<String, TextEditingController> _controllers = {};

  // Dropdown selections, keyed the same way as _controllers.
  final Map<String, String> _dropdowns = {};

  @override
  void initState() {
    super.initState();
    _selected = widget.initialType;
  }

  @override
  void didUpdateWidget(covariant ServicesScreen old) {
    super.didUpdateWidget(old);
    if (old.initialType != widget.initialType) {
      setState(() => _selected = widget.initialType);
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

  String _text(String key) => _ctrl(key).text.trim();

  String _orFallback(String value, String fallback) => value.isEmpty ? fallback : value;

  String _nowLabel() {
    final now = DateTime.now();
    final hour12 = now.hour % 12 == 0 ? 12 : now.hour % 12;
    final minute = now.minute.toString().padLeft(2, '0');
    final period = now.hour >= 12 ? 'PM' : 'AM';
    return 'Today, $hour12:$minute $period';
  }

  void _submit() {
    final refNo = widget.appState.nextRefNo();
    final timeLabel = _nowLabel();

    late List<String> metaLines;
    switch (_selected) {
      case ServiceType.ambulance:
        final patient = _orFallback(_text('amb_patient'), 'Not specified');
        final pickup = _orFallback(_text('amb_pickup'), 'Pick-up location not specified');
        final destination = _orFallback(_text('amb_destination'), 'destination not specified');
        metaLines = [
          'Patient: $patient',
          '$pickup → $destination',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceType.transfer:
        final patient = _orFallback(_text('trf_patient'), 'Not specified');
        final from = _orFallback(_text('trf_from'), 'Current facility not specified');
        final to = _orFallback(_text('trf_to'), 'destination not specified');
        final type = _dropdownValue('trf_type', _transferTypes);
        metaLines = [
          'Patient: $patient',
          '$from → $to',
          'Transfer type: $type',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceType.road:
        final location = _orFallback(_text('road_location'), 'Location not specified');
        final obstruction = _dropdownValue('road_obstruction', _obstructionTypes);
        metaLines = [
          location,
          'Obstruction: $obstruction',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceType.relief:
        final head = _orFallback(_text('relief_head'), 'Not specified');
        final address = _orFallback(_text('relief_address'), 'Address not specified');
        final assistance = _dropdownValue('relief_type', _assistanceTypes);
        metaLines = [
          'Household head: $head',
          address,
          'Assistance: $assistance',
          'Submitted $timeLabel',
        ];
        break;
      case ServiceType.inquiry:
        final subject = _orFallback(_text('inq_subject'), 'General inquiry');
        final method = _dropdownValue('inq_method', _contactMethods);
        metaLines = [
          'Subject: $subject',
          'Preferred contact: $method',
          'Submitted $timeLabel',
        ];
        break;
    }

    final request = ServiceRequest(
      type: _selected,
      refNo: refNo,
      status: ReqStatus.review,
      cancellable: true,
      metaLines: metaLines,
      timeline: [
        TimelineStep('Request submitted', timeLabel, RequestStepState.done),
        const TimelineStep('Forwarded to MDRRMO for review', 'Pending', RequestStepState.pending),
        const TimelineStep('Completed', 'Pending', RequestStepState.pending),
      ],
    );

    widget.appState.addRequest(request);

    final f = widget.appState.language == AppLanguage.filipino;
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _ConfirmationSheet(
        refNo: refNo,
        filipino: f,
        onViewTrack: () {
          Navigator.pop(context);
          widget.onSubmitted();
        },
      ),
    );
  }

  static const _transferTypes = ['One-time transfer', 'Dialysis — recurring schedule', 'Other medical procedure'];
  static const _obstructionTypes = ['Fallen tree / branches', 'Flooding / silt', 'Landslide debris', 'Other'];
  static const _assistanceTypes = ['Food packs', 'Hygiene kits', 'Drinking water', 'Temporary shelter materials', 'Other'];
  static const _contactMethods = ['SMS', 'Phone call', 'In-app reply'];

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
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: 1.5,
                children: [
                  ServiceType.ambulance,
                  ServiceType.transfer,
                  ServiceType.road,
                  ServiceType.relief,
                ].map((t) => _TypeCard(type: t, selected: t == _selected, filipino: f, onTap: () => setState(() => _selected = t))).toList(),
              ),
              const SizedBox(height: 10),
              _TypeCard(
                type: ServiceType.inquiry,
                selected: _selected == ServiceType.inquiry,
                filipino: f,
                onTap: () => setState(() => _selected = ServiceType.inquiry),
                fullWidth: true,
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: _formTitle(_selected, f)),
              _buildForm(_selected),
              const SizedBox(height: 6),
              AppButton(label: tr(f, 'common.submit_request'), onPressed: _submit),
            ],
          ),
        ),
        const SizedBox(height: 110),
      ],
    );
  }

  String _formTitle(ServiceType t, bool f) => switch (t) {
        ServiceType.ambulance => tr(f, 'services.form.ambulance'),
        ServiceType.transfer => tr(f, 'services.form.transfer'),
        ServiceType.road => tr(f, 'services.form.road'),
        ServiceType.relief => tr(f, 'services.form.relief'),
        ServiceType.inquiry => tr(f, 'services.form.inquiry'),
      };

  Widget _buildForm(ServiceType t) {
    switch (t) {
      case ServiceType.ambulance:
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
      case ServiceType.transfer:
        return Column(children: [
          _Field(label: 'Patient name', hint: 'Full name of patient', controller: _ctrl('trf_patient')),
          Row(
            children: [
              Expanded(child: _Field(label: 'Current facility', hint: 'e.g. RHU Echague', controller: _ctrl('trf_from'))),
              const SizedBox(width: 10),
              Expanded(child: _Field(label: 'Destination facility', hint: 'e.g. CVMC', controller: _ctrl('trf_to'))),
            ],
          ),
          _Dropdown(
            label: 'Transfer type',
            items: _transferTypes,
            value: _dropdownValue('trf_type', _transferTypes),
            onChanged: (v) => setState(() => _dropdowns['trf_type'] = v),
          ),
          _Field(
            label: 'Preferred date & time',
            hint: 'e.g. Jun 20, 7:00 AM',
            icon: Icons.event_outlined,
            controller: _ctrl('trf_datetime'),
          ),
          _Field(
            label: 'Contact number',
            hint: '09XXXXXXXXX',
            keyboard: TextInputType.phone,
            maxLength: 11,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            controller: _ctrl('trf_contact'),
          ),
        ]);
      case ServiceType.road:
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
      case ServiceType.relief:
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
      case ServiceType.inquiry:
        return Column(children: [
          _Field(label: 'Subject', hint: 'What is this about?', controller: _ctrl('inq_subject')),
          _Field(
            label: 'Your message',
            hint: 'Type your question here',
            lines: 4,
            controller: _ctrl('inq_message'),
          ),
          _Dropdown(
            label: 'Preferred contact method',
            items: _contactMethods,
            value: _dropdownValue('inq_method', _contactMethods),
            onChanged: (v) => setState(() => _dropdowns['inq_method'] = v),
          ),
          _Field(
            label: 'Contact number',
            hint: '09XXXXXXXXX',
            keyboard: TextInputType.phone,
            maxLength: 11,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            controller: _ctrl('inq_contact'),
          ),
        ]);
    }
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
  final ServiceType type;
  final bool selected;
  final bool filipino;
  final VoidCallback onTap;
  final bool fullWidth;

  const _TypeCard({
    required this.type,
    required this.selected,
    required this.filipino,
    required this.onTap,
    this.fullWidth = false,
  });

  @override
  Widget build(BuildContext context) {
    final card = InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? AppColors.green50 : AppColors.surface,
          border: Border.all(color: selected ? AppColors.green700 : AppColors.line, width: 1.5),
          borderRadius: BorderRadius.circular(14),
        ),
        child: fullWidth
            ? Row(
                children: [
                  IconBadge(icon: type.icon, bg: type.bg, fg: type.fg),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(type.titleFor(filipino), style: AppText.display(size: 12.5, weight: FontWeight.w600)),
                        Text(type.subtitleFor(filipino), style: AppText.body(size: 11, color: AppColors.inkMuted)),
                      ],
                    ),
                  ),
                ],
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconBadge(icon: type.icon, bg: type.bg, fg: type.fg, size: 34, iconSize: 16, radius: 10),
                  const SizedBox(height: 8),
                  Text(
                    type.titleFor(filipino),
                    style: AppText.display(size: 12, weight: FontWeight.w600, height: 1.25),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    type.subtitleFor(filipino),
                    style: AppText.body(size: 10.5, color: AppColors.inkMuted),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
      ),
    );
    return fullWidth ? SizedBox(width: double.infinity, child: card) : card;
  }
}

class _Field extends StatelessWidget {
  final String label;
  final String hint;
  final int lines;
  final TextInputType keyboard;
  final IconData? icon;
  final TextEditingController controller;
  final int? maxLength;
  final List<TextInputFormatter>? inputFormatters;

  const _Field({
    required this.label,
    required this.hint,
    required this.controller,
    this.lines = 1,
    this.keyboard = TextInputType.text,
    this.icon,
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
              suffixIcon: icon != null ? Icon(icon, size: 18, color: AppColors.inkFaint) : null,
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