import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'ambulance_schedule_field.dart';
import 'form_inputs.dart';
import 'feedback.dart' show FormErrorSummary;
import 'form_section.dart';
import 'form_steps.dart';
import 'service_widgets.dart';

/// The ambulance flow's five step names, in order. English keys for [trEn].
const ambulanceStepNames = ['Patient', 'Trip', 'Condition', 'Schedule and ID', 'Review'];

/// Max length of the condition field; the server allows more (5000).
const _conditionMax = 500;

/// Green header: close, title, "Step N of 5 · name", segmented progress.
class AmbulanceStepHeader extends StatelessWidget {
  final int step;
  final bool filipino;
  final VoidCallback onClose;

  const AmbulanceStepHeader({super.key, required this.step, required this.filipino, required this.onClose});

  @override
  Widget build(BuildContext context) => FormStepHeader(
        title: trEn(filipino, 'Request an ambulance'),
        stepNames: ambulanceStepNames,
        step: step,
        filipino: filipino,
        leadingIcon: Icons.close_rounded,
        leadingLabel: trEn(filipino, 'Close'),
        onLeading: onClose,
      );
}

/// Back (hidden on step 1) and Next / Submit, pinned under the step.
class AmbulanceStepFooter extends StatelessWidget {
  final int step;
  final bool filipino;
  final bool submitting;
  final VoidCallback onBack;
  final VoidCallback onNext;

  const AmbulanceStepFooter({
    super.key,
    required this.step,
    required this.filipino,
    required this.submitting,
    required this.onBack,
    required this.onNext,
  });

  @override
  Widget build(BuildContext context) => FormStepFooter(
        step: step,
        stepNames: ambulanceStepNames,
        filipino: filipino,
        submitting: submitting,
        onBack: onBack,
        onNext: onNext,
      );
}

/// The "not for emergencies" note on step 1; taps through to the hotlines.
class _HotlineBar extends StatelessWidget {
  final bool filipino;
  final VoidCallback? onTap;

  const _HotlineBar({required this.filipino, this.onTap});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      side: const BorderSide(color: AppColors.line),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.lg),
      child: Material(
        color: AppColors.surface,
        shape: shape,
        child: InkWell(
          onTap: onTap,
          customBorder: shape,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 52),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              child: Row(
                children: [
                  const Icon(Icons.phone_outlined, size: 20, color: AppColors.red600),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr(f, 'services.notice_title'), style: AppText.body(height: 1.4)),
                        if (onTap != null)
                          Text(
                            tr(f, 'notice.view_hotlines'),
                            style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.red600),
                          ),
                      ],
                    ),
                  ),
                  if (onTap != null) const Icon(Icons.chevron_right_rounded, size: 20, color: AppColors.inkFaint),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The fields of one ambulance step. Values live on [form] (and the drafts),
/// so stepping away and back keeps everything.
class AmbulanceStepFields extends StatelessWidget {
  final int step;
  final AmbulanceFormData form;
  final AppState appState;
  final bool filipino;
  final VoidCallback onChanged;
  final List<String> destinations;
  final List<String> barangays;
  final TextEditingController landmark;

  /// Inline errors for this step, by field: patient, destination, relative, validId.
  final Map<String, String> errors;

  final String? validIdName;
  final VoidCallback onTakePhoto;
  final VoidCallback onChooseFile;
  final VoidCallback? onOpenHotlines;
  final ValueChanged<int> onEdit;

  const AmbulanceStepFields({
    super.key,
    required this.step,
    required this.form,
    required this.appState,
    required this.filipino,
    required this.onChanged,
    required this.destinations,
    required this.barangays,
    required this.landmark,
    required this.errors,
    required this.validIdName,
    required this.onTakePhoto,
    required this.onChooseFile,
    required this.onEdit,
    this.onOpenHotlines,
  });

  /// Step 2 to 5's own heading; step 1 opens on the hotline note instead.
  static const _intros = {
    1: ('ambulance.step2.title', 'ambulance.step2.body'),
    2: ('ambulance.step3.title', 'ambulance.step3.body'),
    3: ('ambulance.step4.title', 'ambulance.step4.body'),
    4: ('ambulance.step5.title', 'ambulance.step5.body'),
  };

  /// The field each step's one check is about, by error key.
  static const _errorFields = {
    'patient': 'Full name',
    'destination': 'Take patient to',
    'relative': 'Relative to contact',
    'validId': 'Valid ID',
  };

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final intro = _intros[step];
    final errorField = errors.isEmpty ? null : _errorFields[errors.keys.first];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (step == 0) _HotlineBar(filipino: f, onTap: onOpenHotlines),
        if (intro != null) FormStepIntro(title: tr(f, intro.$1), body: tr(f, intro.$2)),
        // Each step checks one field, so the summary names it, not a count.
        if (errors.isNotEmpty) ...[
          FormErrorSummary.one(field: errorField == null ? null : trEn(f, errorField), filipino: f),
          const SizedBox(height: AppSpacing.lg),
        ],
        ...switch (step) {
          0 => _patient(),
          1 => _trip(),
          2 => _condition(),
          3 => _scheduleAndId(),
          _ => [_review()],
        },
      ],
    );
  }

  List<Widget> _patient() {
    final f = filipino;
    final other = form.patientBarangay == AmbulanceFormData.barangayOther;
    return [
      Text(trEn(f, 'Who needs the ambulance?'), style: AppText.fieldLabel()),
      const SizedBox(height: AppSpacing.xs),
      // Myself fills the name once; see setPatientIsAccountHolder.
      SegmentedChoice(
        leftLabel: trEn(f, 'Myself'),
        rightLabel: trEn(f, 'Someone else'),
        rightSelected: !form.patientIsAccountHolder,
        onChanged: (someoneElse) {
          form.setPatientIsAccountHolder(!someoneElse);
          onChanged();
        },
      ),
      const SizedBox(height: AppSpacing.md),
      AppTextField(
        label: trEn(f, 'Full name'),
        hint: trEn(f, 'e.g. Maria Santos'),
        controller: form.patient,
        isRequired: true,
        errorText: errors['patient'],
      ),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 96,
            child: AppTextField(
              label: trEn(f, 'Age'),
              hint: trEn(f, 'e.g. 62'),
              keyboard: TextInputType.number,
              controller: form.age,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(child: AppTextField.phone(label: trEn(f, 'Contact number'), controller: form.patientContact)),
        ],
      ),
      SwitchRow(
        label: trEn(f, 'Lives at my address'),
        hint: trEn(f, 'Fills in barangay and purok for you'),
        value: form.patientAddressIsMyAddress,
        onChanged: (on) {
          form.setPatientAddressIsMyAddress(on, barangays);
          onChanged();
        },
      ),
      const SizedBox(height: AppSpacing.sm),
      AppSearchField(
        label: trEn(f, 'Barangay'),
        hint: trEn(f, 'Search barangay'),
        value: form.patientBarangay,
        items: [...barangays, AmbulanceFormData.barangayOther],
        itemLabel: (item) => item == AmbulanceFormData.barangayOther ? trEn(f, item) : item,
        onChanged: (value) {
          form.patientBarangay = value;
          onChanged();
        },
      ),
      other
          ? AppTextField(
              label: trEn(f, 'Full address'),
              hint: trEn(f, 'House no., street, barangay, town'),
              controller: form.patientAddress,
            )
          : AppTextField(label: trEn(f, 'Purok / street'), hint: trEn(f, 'e.g. Purok 3'), controller: form.patientAddress),
    ];
  }

  List<Widget> _trip() {
    final f = filipino;
    return [
      SwitchRow(
        label: trEn(f, 'Pick up at my address'),
        value: form.pickupIsMyAddress,
        onChanged: (on) {
          form.setPickupIsMyAddress(on);
          onChanged();
        },
      ),
      const SizedBox(height: AppSpacing.sm),
      AppSearchField(
        label: trEn(f, 'Pick up from'),
        hint: trEn(f, 'Search or pick Other'),
        value: form.pickupChoice,
        items: [...destinations, AmbulanceFormData.pickupOther],
        itemLabel: (item) => trEn(f, item),
        onChanged: (choice) {
          form.setPickupChoice(choice);
          onChanged();
        },
      ),
      if (form.pickupChoice == AmbulanceFormData.pickupOther)
        AppTextField(
          label: trEn(f, 'Pickup location'),
          hint: trEn(f, 'e.g. Purok 3, San Fabian'),
          controller: form.pickup,
        ),
      AppTextField(
        label: trEn(f, 'Landmark (optional)'),
        hint: trEn(f, 'e.g. beside the chapel'),
        controller: landmark,
        note: trEn(f, 'Helps the driver find you.'),
      ),
      const SizedBox(height: AppSpacing.sm),
      AppSearchField(
        label: trEn(f, 'Take patient to'),
        hint: trEn(f, 'Search or pick Others'),
        value: form.destinationChoice,
        items: [...destinations, AmbulanceFormData.destinationOthers],
        itemLabel: (item) => trEn(f, item),
        isRequired: true,
        onChanged: (choice) {
          form.setDestinationChoice(choice);
          onChanged();
        },
      ),
      if (form.destinationChoice == AmbulanceFormData.destinationOthers)
        AppTextField(
          label: trEn(f, 'Destination name'),
          hint: trEn(f, 'e.g. Echague District Hospital'),
          controller: form.destination,
          isRequired: true,
          errorText: errors['destination'],
        ),
    ];
  }

  List<Widget> _condition() {
    final f = filipino;
    return [
      AppTextField(
        label: trEn(f, 'Condition'),
        hint: trEn(f, 'Symptoms, since when, and whether the patient can walk'),
        lines: 4,
        maxLength: _conditionMax,
        controller: form.diagnosis,
      ),
      // The field hides Flutter's own counter, so the hint and "n / 500" share
      // a line under it.
      ValueListenableBuilder(
        valueListenable: form.diagnosis,
        builder: (_, value, __) => Row(
          children: [
            Expanded(child: Text(trEn(f, 'Plain words are fine.'), style: AppText.detail())),
            Text('${value.text.length} / $_conditionMax', style: AppText.detail()),
          ],
        ),
      ),
      const SizedBox(height: AppSpacing.md),
      for (var i = 0; i < form.relatives.length; i++)
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              // The first is the one asked for; any more are numbered.
              child: AppTextField(
                label: i == 0 ? trEn(f, 'Relative to contact') : trEn(f, 'Relative {n}').replaceAll('{n}', '${i + 1}'),
                hint: trEn(f, 'e.g. Juan Dela Cruz'),
                controller: form.relatives[i],
                isRequired: i == 0,
                errorText: i == 0 ? errors['relative'] : null,
                note: i == 0 ? trEn(f, "We'll call them if we can't reach the patient.") : null,
              ),
            ),
            // Not on a lone row: removing the only relative just blanks it.
            if (form.relatives.length > 1)
              Padding(
                padding: const EdgeInsets.only(top: 22, left: 4),
                child: IconButton(
                  icon: const Icon(Icons.close_rounded, size: 20),
                  color: AppColors.inkFaint,
                  tooltip: trEn(f, 'Remove relative {n}').replaceAll('{n}', '${i + 1}'),
                  onPressed: () {
                    form.removeRelative(i);
                    onChanged();
                  },
                ),
              ),
          ],
        ),
      if (form.relatives.length < AmbulanceFormData.maxRelatives)
        SizedBox(
          width: double.infinity,
          height: 44,
          child: OutlinedButton.icon(
            onPressed: () {
              form.addRelative();
              onChanged();
            },
            icon: const Icon(Icons.add_rounded, size: 18),
            label: Text(
              trEn(f, 'Add another relative'),
              style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600),
            ),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.green700,
              side: const BorderSide(color: AppColors.green600, width: 1.5),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
            ),
          ),
        ),
    ];
  }

  List<Widget> _scheduleAndId() {
    final f = filipino;
    final idError = errors['validId'];
    return [
      AmbulanceScheduleField(form: form, appState: appState, filipino: f, onChanged: onChanged),
      const SizedBox(height: AppSpacing.lg),
      AttachmentUploadField(
        label: trEn(f, 'Valid ID'),
        hint: trEn(f, 'JPG or PNG, up to 2 MB'),
        isRequired: true,
        filipino: f,
        fileName: validIdName,
        onTakePhoto: onTakePhoto,
        onTap: onChooseFile,
        errorText: idError,
      ),
    ];
  }

  Widget _review() {
    final f = filipino;
    final none = trEn(f, 'Not given');
    String or(String value) => value.trim().isEmpty ? none : value.trim();
    String join(Iterable<String> parts) {
      final given = parts.map((p) => p.trim()).where((p) => p.isNotEmpty).toList();
      return given.isEmpty ? none : given.join(' · ');
    }

    final scheduled = form.scheduledAt;
    // Blank pickup: the server uses the resident's registered barangay.
    final pickup = form.pickup.text.trim().isEmpty ? trEn(f, 'Your registered barangay') : form.pickup.text.trim();
    final landmarkText = landmark.text.trim();
    final relatives = form.relativeNames;
    return ReviewList(
      filipino: f,
      rows: [
        (
          label: trEn(f, 'Patient'),
          value: join([
            [form.patient.text, form.age.text].where((p) => p.trim().isNotEmpty).join(', '),
            form.patientContact.text,
            AmbulanceFormData.composeAddress(form.patientBarangay, form.patientAddress) ?? '',
          ]),
          onEdit: () => onEdit(0),
        ),
        (
          label: trEn(f, 'Trip'),
          value: '$pickup${landmarkText.isEmpty ? '' : ' ($landmarkText)'} → ${or(form.destination.text)}',
          onEdit: () => onEdit(1),
        ),
        (
          label: trEn(f, 'Condition'),
          value: join([form.diagnosis.text, if (relatives.isNotEmpty) '${trEn(f, 'Relatives')}: ${relatives.join(', ')}']),
          onEdit: () => onEdit(2),
        ),
        (
          label: tr(f, 'ambulance_schedule.title'),
          value: scheduled == null ? tr(f, 'ambulance_schedule.asap') : formatBookingConfirmationTime(scheduled, f),
          onEdit: () => onEdit(3),
        ),
        (
          label: trEn(f, 'Valid ID'),
          value: validIdName ?? none,
          onEdit: () => onEdit(3),
        ),
      ],
    );
  }
}
