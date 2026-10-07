library serbis.screens.service_request_form;

import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/ambulance_steps.dart';
import '../widgets/feedback.dart' show FormErrorSummary;
import '../widgets/form_inputs.dart';
import '../widgets/form_section.dart';
import '../widgets/form_steps.dart';
import '../widgets/service_form_fields.dart';
import '../widgets/service_widgets.dart';
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';

/// The relief form's three steps. English keys for [trEn].
const reliefStepNames = ['Household', 'Assistance and delivery', 'ID and review'];

/// One service's form, from its title to the Submit button, as a column with no
/// scroll of its own: the host (a service page) supplies the header and the
/// list it scrolls in.
///
/// The ambulance is the exception: it fills its host (the Ambulance tab) as a
/// five-step flow with its own header and footer, and submits from the last
/// step. The shell routes Android back to [ServiceRequestFormState.handleBack].
///
/// It owns the three jobs only a form can do: keep the resident's answers in
/// [ServiceDrafts], check them, and submit.
class ServiceRequestForm extends StatefulWidget {
  final AppState appState;
  final AppUser user;
  final ServiceCatalogItem service;
  final ServiceDrafts drafts;

  /// The resident chose "View in Track" on the confirmation sheet.
  final VoidCallback onSubmitted;

  /// The server refused the submit because this service was disabled. The
  /// host closes the form; the refusal is already queued as a snackbar.
  final VoidCallback? onServiceUnavailable;

  /// Opens the Library's hotlines, from the ambulance safety notice.
  final VoidCallback? onOpenHotlines;

  /// The form was closed: X or back on the ambulance's step 1, or back on the
  /// first step of any other form.
  final VoidCallback? onExit;

  /// The header bell, on the forms that draw their own header.
  final VoidCallback? onOpenNotifications;

  const ServiceRequestForm({
    super.key,
    required this.appState,
    required this.user,
    required this.service,
    required this.drafts,
    required this.onSubmitted,
    this.onServiceUnavailable,
    this.onOpenHotlines,
    this.onExit,
    this.onOpenNotifications,
  });

  @override
  State<ServiceRequestForm> createState() => ServiceRequestFormState();
}

class ServiceRequestFormState extends State<ServiceRequestForm> {
  /// The ambulance form's destination dropdown (MDRRMO feedback, 2026-09-19).
  /// A local copy, for the reason the catalogue used to be one — `setState` is
  /// what makes the fetch visible on screen.
  List<String> _ambulanceDestinations = [];

  /// Barangay names for the address dropdowns, copied locally for the same
  /// reason as [_ambulanceDestinations].
  List<String> _barangays = [];

  /// True after a submit that never reached the server. Drives a persistent
  /// error card with Retry — a snackbar alone auto-dismisses, and the previous
  /// code showed a success sheet instead.
  bool _submitFailed = false;

  /// True while the multipart POST is in flight, so the button can show a
  /// spinner and refuse repeat taps.
  bool _submitting = false;

  final _idKey = GlobalKey();
  final _letterKey = GlobalKey();
  final _dateKey = GlobalKey();

  ServiceDrafts get _drafts => widget.drafts;
  ServiceCatalogItem get _service => widget.service;

  @override
  void initState() {
    super.initState();
    if (_service.formKind == ServiceFormKind.ambulance) {
      _loadAmbulanceDestinations();
      _loadBarangays();
    }
  }

  Future<void> _loadAmbulanceDestinations() async {
    await widget.appState.loadAmbulanceDestinations();
    if (!mounted) return;
    setState(() => _ambulanceDestinations = List.of(widget.appState.ambulanceDestinations));
  }

  Future<void> _loadBarangays() async {
    await widget.appState.loadBarangayNames();
    if (!mounted) return;
    setState(() => _barangays = List.of(widget.appState.barangayNames));
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
      case ServiceFormKind.training:
      case ServiceFormKind.drill:
      case ServiceFormKind.certification:
        return ServiceType.inquiry;
    }
  }

  /// Which uploads a kind of service asks for. The response services take a
  /// photo of a valid ID; the programs take a request letter instead.
  ServiceAttachments _attachmentsFor(ServiceFormKind kind) => switch (kind) {
        ServiceFormKind.training || ServiceFormKind.drill => ServiceAttachments.letterRequired,
        ServiceFormKind.certification => ServiceAttachments.letterOptional,
        _ => ServiceAttachments.standard,
      };

  /// The submission time as it goes into the description. Always English and
  /// always the device's local clock: this string is read by a dispatcher in
  /// the admin panel, not by the resident.
  String _nowLabel() => formatTimelineTime(DateTime.now(), false);

  Future<void> _pickValidId() async {
    final picked = await _pickImage();
    if (picked != null) {
      setState(() {
        _drafts.validId = picked;
        _errors = const {};
      });
    }
  }

  /// Camera capture for the valid ID. Downscaled so a phone photo stays under
  /// the server's 2 MB cap.
  Future<void> _takeValidIdPhoto() async {
    final shot = await ImagePicker().pickImage(
      source: ImageSource.camera,
      maxWidth: 1600,
      imageQuality: 80,
    );
    if (shot == null) return;
    final bytes = await shot.readAsBytes();
    final name = shot.name.contains('.') ? shot.name : '${shot.name}.jpg';
    if (!mounted) return;
    setState(() {
      _drafts.validId = fp.PlatformFile(name: name, size: bytes.length, bytes: bytes);
      _errors = const {};
    });
  }

  Future<void> _pickSitePhoto() async {
    final picked = await _pickImage();
    if (picked != null) {
      setState(() => _drafts.sitePhoto = picked);
    }
  }

  Future<void> _pickLetter() async {
    final result = await fp.FilePicker.platform.pickFiles(
      type: fp.FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
      withData: true,
    );
    if (result == null || result.files.isEmpty) {
      return;
    }
    setState(() {
      _drafts.letter = result.files.first;
      _errors = const {};
    });
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

  /// Shows [errors] under their fields and scrolls the first one into view.
  void _refuse(Map<String, String> errors, GlobalKey field) {
    setState(() => _errors = errors);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final target = field.currentContext;
      if (target != null) Scrollable.ensureVisible(target, duration: const Duration(milliseconds: 200));
    });
  }

  Future<void> _submit() async {
    // The multipart upload carries a photo and has a 30-second timeout. Without
    // this guard every extra tap in that window filed another live request in
    // the dispatcher's queue.
    if (_submitting) {
      return;
    }

    final service = _service;
    final attachments = _attachmentsFor(service.formKind);
    final fil = widget.appState.language == AppLanguage.filipino;

    if (attachments == ServiceAttachments.standard &&
        (_drafts.validId == null || _drafts.validId!.bytes == null)) {
      _refuse({'validId': trEn(fil, 'Attach a photo of a valid ID.')}, _idKey);
      return;
    }

    final form = _drafts.formFor(service.formKind);

    // The callback-number guard is gone with the field it guarded. It refused a
    // submit when the resident left the number blank; the number now comes off
    // the account, where `phone_number` is required at registration and NOT
    // NULL, so there is nothing left to be blank.

    // The ambulance's required fields (the three the server requires) are
    // checked step by step in _stepErrors, before Submit can be reached.

    // The programs are booked for a day and need the office's lead time, so the
    // date and the letter are checked here; the server checks them again.
    if (form is StructuredFormData) {
      final dateField = form.spec.fields.where((field) => field.isDate).firstOrNull;
      if (dateField != null) {
        final picked = form.date(dateField.key);
        final today = DateTime.now();
        final earliest = DateTime(today.year, today.month, today.day)
            .add(Duration(days: dateField.minDaysAhead));
        if (picked == null || picked.isBefore(earliest)) {
          _refuse({
            dateField.key: picked == null
                ? trEn(fil, 'Please choose a preferred date.')
                : trEn(fil, 'Choose a date at least {n} days from today.')
                    .replaceAll('{n}', '${dateField.minDaysAhead}'),
          }, _dateKey);
          return;
        }
      }

      if (form.spec.attachments == ServiceAttachments.letterRequired &&
          (_drafts.letter == null || _drafts.letter!.bytes == null)) {
        _refuse({'letter': trEn(fil, 'Please attach your request letter before submitting.')}, _letterKey);
        return;
      }
    }

    final metaLines = form.metaLines(
      serviceName: service.name,
      submittedLabel: _nowLabel(),
    );

    // Null on every form but the ambulance one, and null there too unless the
    // resident picked a date and time — "as soon as possible" either way.
    final scheduledAt = form is AmbulanceFormData ? form.scheduledAt : null;

    final request = ServiceRequest(
      serviceId: service.isOthers ? null : service.id,
      description: metaLines.join('\n'),
      type: _typeForKind(service.formKind),
      // Empty until the server answers: the reference number is the server's
      // request_id, and inventing one locally gave the resident a number that
      // matched no record in tbl_service_request.
      refNo: '',
      status: ReqStatus.review,
      // The server decides; its 201 carries can_cancel.
      cancellable: false,
      metaLines: metaLines,
      // The server's created_at replaces this the moment the row comes back;
      // until then the timeline still has a real submission time to show.
      createdAt: DateTime.now(),
      scheduledAt: scheduledAt,
    );

    final sendsSitePhoto = service.formKind != ServiceFormKind.ambulance;

    setState(() => _submitting = true);

    ServiceRequest? confirmed;
    try {
      confirmed = await widget.appState.addRequest(
        request,
        // Empty for the programs, which ask for a letter instead of an ID.
        validIdFileBytes: _drafts.validId?.bytes ?? const <int>[],
        validIdFileName: _drafts.validId?.name ?? '',
        preferredDate: form is StructuredFormData ? form.preferredDate : null,
        letterBytes: _drafts.letter?.bytes,
        letterFileName: _drafts.letter?.bytes == null ? null : _drafts.letter?.name,
        // `bytes` is null when the picker returns a path-only file, which is
        // what happens if `withData` ever stops holding. Sending the name
        // without the bytes would be a 422 on an upload the resident is not
        // required to make at all.
        // Never for an ambulance, which has no site photo slot — the drafts
        // are shared, so a photo picked on the road form could still be here.
        sitePhotoBytes: sendsSitePhoto ? _drafts.sitePhoto?.bytes : null,
        sitePhotoFileName:
            sendsSitePhoto && _drafts.sitePhoto?.bytes != null ? _drafts.sitePhoto?.name : null,
        landmark: _drafts.landmark.text.trim().isEmpty ? null : _drafts.landmark.text.trim(),
        // Relief goods only (StructuredFormData.offersFulfillment) — pickup/
        // delivery beyond equipment borrowing, MDRRMO feedback, 2026-09-18.
        fulfillmentMethod: form is StructuredFormData && form.offersFulfillment
            ? form.fulfillmentMethod
            : null,
        deliveryAddress: form is StructuredFormData && form.offersFulfillment
            ? form.deliveryAddress.text.trim()
            : null,
        // Ambulance only. Its presence is what tells the request builder to
        // send the structured columns and omit `description` entirely — the
        // server composes that from these same values, and a client-composed
        // one would be a second composer on the wire.
        intake: form is AmbulanceFormData ? AmbulanceIntake.from(form) : null,
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
      final unavailable = widget.appState.unavailableServiceId;
      if (unavailable != null && unavailable == service.id) {
        widget.onServiceUnavailable?.call();
        return;
      }
      setState(() => _submitFailed = true);
      return;
    }

    // The ID is kept — it is the same ID next time, and re-picking it is pure
    // friction. The site photo is not: it is a photo of one incident, and
    // leaving it attached would silently file the last scene with the next
    // request. On the failure path above it stays, because Retry has to cost
    // one tap.
    setState(() {
      _submitFailed = false;
      _drafts.sitePhoto = null;
      _drafts.letter = null;
      _drafts.landmark.clear();
      // Filed: the next ambulance request starts on a blank step 1, and a
      // stepped form is back on its first so Back leaves the page.
      if (service.formKind == ServiceFormKind.ambulance) _drafts.discardAmbulance();
      _step = 0;
      _errors = const {};
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
        request: filed,
        title: service.displayName(f),
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
    if (_service.formKind == ServiceFormKind.ambulance) return _buildAmbulance(f);
    return _buildStructured(f);
  }

  bool get _isRelief => _service.formKind == ServiceFormKind.relief;

  /// Relief is three steps; every other structured form is one page.
  List<String> get _structuredSteps => _isRelief ? reliefStepNames : const ['Details'];

  StructuredFormData get _structuredForm => _drafts.formFor(_service.formKind) as StructuredFormData;

  /// Header, the showing step (scrolls on its own), footer. The same shape as
  /// the ambulance flow, under the shared title header instead of its own.
  Widget _buildStructured(bool f) {
    final steps = _structuredSteps;
    final stepped = steps.length > 1;
    return Column(
      children: [
        if (stepped)
          FormStepHeader(
            title: _service.displayName(f),
            stepNames: steps,
            step: _step,
            filipino: f,
            // Back, not Close: it steps back through the form before leaving.
            leadingIcon: Icons.arrow_back_rounded,
            leadingLabel: tr(f, 'nav.back'),
            onLeading: handleBack,
            onNotifications: widget.onOpenNotifications,
          )
        else
          TabHeaderBar(
            title: _service.displayName(f),
            subtitle: tr(f, 'services.subtitle'),
            filipino: f,
            onBack: handleBack,
            onNotifications: widget.onOpenNotifications,
          ),
        Expanded(
          child: ListView(
            // A new key per step, so each step opens at its top.
            key: ValueKey(_step),
            padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.lg, AppLayout.gutter, AppSpacing.xl),
            children: [
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 600),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // One field at a time is refused, so this names that
                      // field rather than counting; the field says what to do.
                      if (_errors.isNotEmpty && !stepped) ...[
                        FormErrorSummary.one(field: _refusedFieldName(f), filipino: f),
                        const SizedBox(height: AppSpacing.lg),
                      ],
                      ..._structuredBody(f),
                      if (_submitFailed && _step == steps.length - 1) SubmitErrorCard(filipino: f, onRetry: _submit),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        FormStepFooter(
          step: _step,
          stepNames: steps,
          filipino: f,
          submitting: _submitting,
          onBack: handleBack,
          onNext: _nextStructured,
        ),
      ],
    );
  }

  void _nextStructured() {
    if (_step == _structuredSteps.length - 1) {
      _submit();
    } else {
      _goToStep(_step + 1);
    }
  }

  List<Widget> _structuredBody(bool f) {
    final form = _structuredForm;
    final description = _service.displayDescription(f);
    final intro = description.isEmpty
        ? const <Widget>[]
        : [
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.lg),
              child: Text(
                description,
                style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkMuted, height: 1.5),
              ),
            ),
          ];

    if (!_isRelief) {
      return [
        ...intro,
        ServiceFormFields(
          data: form,
          onChanged: () => setState(() => _errors = const {}),
          filipino: f,
          errors: _errors,
          dateKey: _dateKey,
          firstSectionExtra: _landmarkField(f),
        ),
        FormSection(label: tr(f, 'form_section.attachments'), children: _attachmentFields(f)),
      ];
    }

    return switch (_step) {
      0 => [
          FormStepIntro(title: tr(f, 'relief.step1.title'), body: tr(f, 'relief.step1.body')),
          ServiceFormFields(
            data: form,
            onChanged: () => setState(() {}),
            filipino: f,
            sectionIndex: 0,
            fulfillment: false,
            labels: false,
          ),
        ],
      1 => [
          FormStepIntro(title: tr(f, 'relief.step2.title'), body: tr(f, 'relief.step2.body')),
          ServiceFormFields(
            data: form,
            onChanged: () => setState(() {}),
            filipino: f,
            sectionIndex: 1,
            labels: false,
          ),
        ],
      _ => [
          FormStepIntro(title: tr(f, 'relief.step3.title'), body: tr(f, 'relief.step3.body')),
          ..._attachmentFields(f),
          _landmarkField(f),
          const SizedBox(height: AppSpacing.md),
          _reliefReview(f, form),
        ],
    };
  }

  /// The uploads a kind of service asks for, each with its inline error.
  List<Widget> _attachmentFields(bool f) {
    final attachments = _attachmentsFor(_service.formKind);
    if (attachments != ServiceAttachments.standard) {
      return [
        KeyedSubtree(
          key: _letterKey,
          child: AttachmentUploadField(
            label: attachments == ServiceAttachments.letterRequired
                ? trEn(f, 'Request letter (required)')
                : trEn(f, 'Supporting document (optional)'),
            hint: trEn(f, 'PDF, JPG or PNG, up to 4 MB'),
            filipino: f,
            fileName: _drafts.letter?.name,
            errorText: _errors['letter'],
            onTap: _pickLetter,
            onClear: attachments == ServiceAttachments.letterOptional
                ? () => setState(() => _drafts.letter = null)
                : null,
          ),
        ),
      ];
    }
    return [
      KeyedSubtree(
        key: _idKey,
        child: AttachmentUploadField(
          label: trEn(f, 'Valid ID (required)'),
          hint: trEn(f, 'JPG or PNG, up to 2 MB'),
          filipino: f,
          fileName: _drafts.validId?.name,
          errorText: _errors['validId'],
          onTap: _pickValidId,
        ),
      ),
      AttachmentUploadField(
        label: trEn(f, 'Site photo (optional)'),
        hint: trEn(f, 'A photo of a nearby landmark. JPG or PNG, up to 4 MB'),
        filipino: f,
        fileName: _drafts.sitePhoto?.name,
        onTap: _pickSitePhoto,
        onClear: () => setState(() => _drafts.sitePhoto = null),
      ),
    ];
  }

  /// The refused field's name as the form shows it, or null when the key is
  /// not one this form knows.
  String? _refusedFieldName(bool f) {
    final key = _errors.keys.first;
    if (key == 'validId') return trEn(f, 'Valid ID');
    if (key == 'letter') return trEn(f, 'Request letter');
    final field = _structuredForm.spec.fields.where((field) => field.key == key).firstOrNull;
    return field == null ? null : trEn(f, field.label);
  }

  Widget _landmarkField(bool f) => AppTextField(
        label: trEn(f, 'Landmark (optional)'),
        hint: trEn(f, 'e.g. beside the chapel'),
        controller: _drafts.landmark,
      );

  /// What the resident entered on the first two relief steps, one row per
  /// step with Edit back to it: "Juan Dela Cruz · 5 · Purok 3, San Fabian".
  Widget _reliefReview(bool f, StructuredFormData form) {
    String answers(Iterable<String> values) {
      final given = values.map((v) => v.trim()).where((v) => v.isNotEmpty).toList();
      return given.isEmpty ? trEn(f, 'Not given') : given.join(' · ');
    }

    final delivery = form.fulfillmentMethod == 'Delivery';
    final values = [
      [for (final key in const ['household_head', 'household_size', 'address']) form.field(key).text],
      [
        trEn(f, form.choice('assistance')),
        trEn(f, form.fulfillmentMethod),
        if (delivery) form.deliveryAddress.text,
      ],
    ];
    return ReviewList(
      filipino: f,
      rows: [
        for (var i = 0; i < values.length; i++)
          (label: trEn(f, reliefStepNames[i]), value: answers(values[i]), onEdit: () => _goToStep(i)),
      ],
    );
  }

  /// Ambulance only: which of the five steps is showing, and the inline
  /// errors the last Next found on it.
  int _step = 0;
  Map<String, String> _errors = const {};

  AmbulanceFormData get _ambulanceForm => _drafts.formFor(ServiceFormKind.ambulance) as AmbulanceFormData;

  void _goToStep(int step) => setState(() {
        _step = step;
        _errors = const {};
      });

  /// Checks only the showing step. Same three fields the server requires,
  /// plus the valid ID.
  Map<String, String> _stepErrors(bool f) {
    final form = _ambulanceForm;
    return switch (_step) {
      0 when form.patient.text.trim().isEmpty => {'patient': trEn(f, 'Enter the patient name.')},
      1 when form.destination.text.trim().isEmpty => {'destination': trEn(f, 'Enter where the ambulance should go.')},
      2 when form.relativeNames.isEmpty => {
          'relative': trEn(f, 'Name at least one relative going with the patient.')
        },
      3 when _drafts.validId?.bytes == null => {'validId': trEn(f, 'Attach a photo of a valid ID.')},
      _ => const {},
    };
  }

  void _next(bool f) {
    final errors = _stepErrors(f);
    if (errors.isNotEmpty) {
      setState(() => _errors = errors);
      return;
    }
    if (_step == ambulanceStepNames.length - 1) {
      _submit();
    } else {
      _goToStep(_step + 1);
    }
  }

  /// Android back (routed here by the shell) and the footer's Back.
  void handleBack() => _step > 0
      ? _goToStep(_step - 1)
      : _service.formKind == ServiceFormKind.ambulance
          ? _exit()
          : widget.onExit?.call();

  /// True while Back would move to an earlier step rather than leave the form.
  bool get canStepBack => _step > 0;

  /// X, or back on step 1: ask before discarding anything entered, then leave.
  Future<void> _exit() async {
    if (_ambulanceForm.isDirty || _drafts.landmark.text.trim().isNotEmpty) {
      final f = widget.appState.language == AppLanguage.filipino;
      final discard = await showConfirmDialog(
        context,
        title: trEn(f, 'Discard this request?'),
        body: trEn(f, 'What you entered will be cleared.'),
        keepLabel: trEn(f, 'Keep editing'),
        confirmLabel: trEn(f, 'Discard'),
      );
      if (!discard || !mounted) return;
      _drafts.discardAmbulance();
      setState(() {
        _step = 0;
        _errors = const {};
        _submitFailed = false;
      });
    }
    widget.onExit?.call();
  }

  /// Header, the showing step (scrolls on its own), footer.
  Widget _buildAmbulance(bool f) {
    return Column(
      children: [
        AmbulanceStepHeader(step: _step, filipino: f, onClose: _exit),
        Expanded(
          child: ListView(
            // A new key per step, so each step opens at its top.
            key: ValueKey(_step),
            padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.lg, AppLayout.gutter, AppSpacing.xl),
            children: [
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 600),
                  child: Column(
                    children: [
                      AmbulanceStepFields(
                        step: _step,
                        form: _ambulanceForm,
                        appState: widget.appState,
                        filipino: f,
                        onChanged: () => setState(() {}),
                        destinations: _ambulanceDestinations,
                        barangays: _barangays,
                        landmark: _drafts.landmark,
                        errors: _errors,
                        validIdName: _drafts.validId?.name,
                        onTakePhoto: _takeValidIdPhoto,
                        onChooseFile: _pickValidId,
                        onOpenHotlines: widget.onOpenHotlines,
                        onEdit: _goToStep,
                      ),
                      if (_submitFailed && _step == ambulanceStepNames.length - 1)
                        SubmitErrorCard(filipino: f, onRetry: _submit),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        AmbulanceStepFooter(
          step: _step,
          filipino: f,
          submitting: _submitting,
          onBack: handleBack,
          onNext: () => _next(f),
        ),
      ],
    );
  }
}
