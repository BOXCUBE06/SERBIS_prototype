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
import '../widgets/form_inputs.dart';
import '../widgets/ambulance_steps.dart';
import '../widgets/form_section.dart';
import '../widgets/service_form_fields.dart';
import '../widgets/service_widgets.dart';
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';

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

  /// The ambulance flow was closed (X, or back on step 1).
  final VoidCallback? onExit;

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
    setState(() => _drafts.letter = result.files.first);
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

    final service = _service;
    final attachments = _attachmentsFor(service.formKind);
    final fil = widget.appState.language == AppLanguage.filipino;

    if (attachments == ServiceAttachments.standard &&
        (_drafts.validId == null || _drafts.validId!.bytes == null)) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(trEn(fil, 'Please attach a photo of your valid ID before submitting.'))),
      );
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
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(picked == null
                ? trEn(fil, 'Please choose a preferred date.')
                : trEn(fil, 'Choose a date at least {n} days from today.')
                    .replaceAll('{n}', '${dateField.minDaysAhead}'))),
          );
          return;
        }
      }

      if (form.spec.attachments == ServiceAttachments.letterRequired &&
          (_drafts.letter == null || _drafts.letter!.bytes == null)) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(trEn(fil, 'Please attach your request letter before submitting.'))),
        );
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
      // Filed: the next ambulance request starts on a blank step 1.
      if (service.formKind == ServiceFormKind.ambulance) {
        _drafts.discardAmbulance();
        _step = 0;
      }
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
        scheduledAt: filed.scheduledAt,
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
    final service = _service;
    final kind = service.formKind;
    final attachments = _attachmentsFor(kind);
    final description = service.displayDescription(f);

    if (kind == ServiceFormKind.ambulance) return _buildAmbulance(f);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: service.displayName(f)),
        if (description.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: Text(
              description,
              style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkMuted, height: 1.5),
            ),
          ),
        ServiceFormFields(
          data: _drafts.formFor(kind) as StructuredFormData,
          onChanged: () => setState(() {}),
          filipino: f,
        ),
        FormSection(
          label: tr(f, 'form_section.attachments'),
          children: [
            if (attachments != ServiceAttachments.standard)
              AttachmentUploadField(
                label: attachments == ServiceAttachments.letterRequired
                    ? trEn(f, 'Request letter (required)')
                    : trEn(f, 'Supporting document (optional)'),
                hint: trEn(f, 'Tap to upload a photo or PDF (jpg/png/pdf, max 4MB)'),
                filipino: f,
                fileName: _drafts.letter?.name,
                onTap: _pickLetter,
                onClear: attachments == ServiceAttachments.letterOptional
                    ? () => setState(() => _drafts.letter = null)
                    : null,
              )
            else ...[
              AttachmentUploadField(
                label: trEn(f, 'Valid ID (required)'),
                hint: trEn(f, 'Tap to upload a photo of a valid ID (jpg/png, max 2MB)'),
                filipino: f,
                fileName: _drafts.validId?.name,
                onTap: _pickValidId,
              ),
              AttachmentUploadField(
                label: trEn(f, 'Site photo (optional)'),
                hint: trEn(f, 'Tap to add a photo of a nearby landmark (jpg/png, max 4MB)'),
                filipino: f,
                fileName: _drafts.sitePhoto?.name,
                onTap: _pickSitePhoto,
                onClear: () => setState(() => _drafts.sitePhoto = null),
              ),
            ],
          ],
        ),
        const SizedBox(height: 12),
        AppTextField(
          label: trEn(f, 'Landmark (optional)'),
          hint: trEn(f, 'e.g. beside the chapel'),
          controller: _drafts.landmark,
        ),
        if (_submitFailed) SubmitErrorCard(filipino: f, onRetry: _submit),
        const SizedBox(height: 6),
        AppButton(
          label: tr(f, 'common.submit_request'),
          onPressed: _submit,
          loading: _submitting,
        ),
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
  void handleBack() => _step > 0 ? _goToStep(_step - 1) : _exit();

  /// X, or back on step 1: ask before discarding anything entered, then leave.
  Future<void> _exit() async {
    if (_ambulanceForm.isDirty || _drafts.landmark.text.trim().isNotEmpty) {
      final f = widget.appState.language == AppLanguage.filipino;
      final discard = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.xl)),
          title: Text(trEn(f, 'Discard this request?'), style: AppText.display(size: AppTextSize.title)),
          content: Text(
            trEn(f, 'What you entered will be cleared.'),
            style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
          ),
          actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(
                trEn(f, 'Keep editing'),
                style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.inkMuted),
              ),
            ),
            TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: TextButton.styleFrom(backgroundColor: AppColors.red50, foregroundColor: AppColors.red600),
              child: Text(
                trEn(f, 'Discard'),
                style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.red600),
              ),
            ),
          ],
        ),
      );
      if (discard != true || !mounted) return;
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
