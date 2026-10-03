import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'ambulance_schedule_field.dart';
import 'form_inputs.dart';
import 'form_section.dart';
import 'form_steps.dart';
import 'service_widgets.dart';

/// The ambulance flow's five step names, in order. English keys for [trEn].
const ambulanceStepNames = ['Patient', 'Trip', 'Condition', 'Schedule & ID', 'Review'];

/// Max length of the condition field; the server allows more (5000).
const _conditionMax = 500;

/// Green header: close, title, "Step N of 5 · name", segmented progress.
class AmbulanceStepHeader extends StatelessWidget {
  final int step;
  final bool filipino;
  final VoidCallback onClose;

  const AmbulanceStepHeader({super.key, required this.step, required this.filipino, required this.onClose});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final total = ambulanceStepNames.length;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(AppSpacing.lg, AppLayout.readerHeaderTop, AppSpacing.lg, 18),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.xxl)),
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Material(
                    color: Colors.white.withValues(alpha: .14),
                    shape: CircleBorder(side: BorderSide(color: Colors.white.withValues(alpha: .3))),
                    child: IconButton(
                      onPressed: onClose,
                      tooltip: trEn(f, 'Close'),
                      icon: const Icon(Icons.close_rounded, color: Colors.white),
                      constraints: const BoxConstraints.tightFor(width: 44, height: 44),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          trEn(f, 'Request an ambulance'),
                          style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: Colors.white),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${trEn(f, 'Step {n} of {total}').replaceAll('{n}', '${step + 1}').replaceAll('{total}', '$total')}'
                          ' · ${trEn(f, ambulanceStepNames[step])}',
                          style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .88)),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              Row(
                children: [
                  for (var i = 0; i < total; i++) ...[
                    if (i > 0) const SizedBox(width: AppSpacing.xs),
                    Expanded(
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        height: 4,
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: i <= step ? 1 : .3),
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
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

/// One step's answers on the Review step, with Edit jumping back to it.
class AmbulanceReviewCard extends StatelessWidget {
  final String title;
  final List<(String, String)> rows;
  final bool filipino;
  final VoidCallback onEdit;

  const AmbulanceReviewCard({
    super.key,
    required this.title,
    required this.rows,
    required this.filipino,
    required this.onEdit,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.fromLTRB(AppSpacing.lg, AppSpacing.xs, AppSpacing.xs, AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.green900),
                ),
              ),
              TextButton(
                onPressed: onEdit,
                style: TextButton.styleFrom(minimumSize: const Size(44, 44), foregroundColor: AppColors.green700),
                child: Text(trEn(filipino, 'Edit'), style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600)),
              ),
            ],
          ),
          for (final (label, value) in rows)
            Padding(
              padding: const EdgeInsets.only(right: AppSpacing.md, top: AppSpacing.xs),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 104,
                    child: Text(label, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
                  ),
                  Expanded(child: Text(value, style: AppText.body(size: AppTextSize.body))),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Slim "not for emergencies" bar on step 1; taps through to the hotlines.
class _HotlineBar extends StatelessWidget {
  final bool filipino;
  final VoidCallback? onTap;

  const _HotlineBar({required this.filipino, this.onTap});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Material(
        color: AppColors.amber50,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          side: const BorderSide(color: Color(0xFFF1DDC0)),
        ),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(AppRadius.md),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: 10),
            child: Row(
              children: [
                const Icon(Icons.call_rounded, size: 18, color: AppColors.amber600),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        tr(f, 'services.notice_title'),
                        style: AppText.display(size: AppTextSize.small, weight: FontWeight.w700, color: AppColors.amber600),
                      ),
                      if (onTap != null)
                        Text(tr(f, 'notice.view_hotlines'), style: AppText.body(size: AppTextSize.small, color: AppColors.ink)),
                    ],
                  ),
                ),
                if (onTap != null) const Icon(Icons.chevron_right_rounded, color: AppColors.amber600),
              ],
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

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    if (step == 4) return _review();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (step == 0) _HotlineBar(filipino: f, onTap: onOpenHotlines),
        NumberedCard(
          number: step + 1,
          title: trEn(f, ambulanceStepNames[step]),
          children: switch (step) {
            0 => _patient(),
            1 => _trip(),
            2 => _condition(),
            _ => _scheduleAndId(),
          },
        ),
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
        label: trEn(f, 'Patient name'),
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
      _RouteStop(
        isDestination: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
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
              note: trEn(f, 'Helps the driver find you faster.'),
            ),
          ],
        ),
      ),
      _RouteStop(
        isDestination: true,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
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
          ],
        ),
      ),
    ];
  }

  List<Widget> _condition() {
    final f = filipino;
    return [
      AppTextField(
        label: trEn(f, 'Medical diagnosis'),
        hint: trEn(f, 'Symptoms, since when, and whether the patient can walk'),
        lines: 4,
        maxLength: _conditionMax,
        controller: form.diagnosis,
      ),
      // The field hides Flutter's own counter, so draw "n/500" here.
      ValueListenableBuilder(
        valueListenable: form.diagnosis,
        builder: (_, value, __) => Align(
          alignment: Alignment.centerRight,
          child: Text(
            '${value.text.length}/$_conditionMax',
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
          ),
        ),
      ),
      const SizedBox(height: AppSpacing.md),
      for (var i = 0; i < form.relatives.length; i++)
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: AppTextField(
                label: trEn(f, 'Relative {n}').replaceAll('{n}', '${i + 1}'),
                hint: trEn(f, 'e.g. Juan Dela Cruz'),
                controller: form.relatives[i],
                isRequired: i == 0,
                errorText: i == 0 ? errors['relative'] : null,
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
      Text(tr(f, 'ambulance_schedule.title'), style: AppText.fieldLabel()),
      const SizedBox(height: AppSpacing.xs),
      AmbulanceScheduleField(form: form, appState: appState, filipino: f, onChanged: onChanged),
      const SizedBox(height: AppSpacing.lg),
      Row(children: [Text(trEn(f, 'Valid ID'), style: AppText.fieldLabel()), const RequiredMark()]),
      const SizedBox(height: AppSpacing.xs),
      IdUploadCard(filipino: f, fileName: validIdName, onTakePhoto: onTakePhoto, onChooseFile: onChooseFile),
      if (idError != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xs, left: 2),
          child: Text(idError, style: AppText.body(size: AppTextSize.caption, color: AppColors.red600)),
        ),
    ];
  }

  Widget _review() {
    final f = filipino;
    final none = trEn(f, 'Not given');
    String or(String value) => value.trim().isEmpty ? none : value.trim();
    final scheduled = form.scheduledAt;
    final cards = [
      [
        (trEn(f, 'Patient name'), or(form.patient.text)),
        (trEn(f, 'Age'), or(form.age.text)),
        (trEn(f, 'Contact number'), or(form.patientContact.text)),
        (trEn(f, 'Address'), AmbulanceFormData.composeAddress(form.patientBarangay, form.patientAddress) ?? none),
      ],
      [
        // Blank pickup: the server uses the resident's registered barangay.
        (trEn(f, 'Pick up from'), form.pickup.text.trim().isEmpty ? trEn(f, 'Your registered barangay') : form.pickup.text.trim()),
        (trEn(f, 'Landmark'), or(landmark.text)),
        (trEn(f, 'Take patient to'), or(form.destination.text)),
      ],
      [
        (trEn(f, 'Medical diagnosis'), or(form.diagnosis.text)),
        (trEn(f, 'Relatives'), form.relativeNames.isEmpty ? none : form.relativeNames.join(', ')),
      ],
      [
        (tr(f, 'ambulance_schedule.title'),
            scheduled == null ? tr(f, 'ambulance_schedule.asap') : formatBookingConfirmationTime(scheduled, f)),
        (trEn(f, 'Valid ID'), validIdName ?? none),
      ],
    ];
    return Column(
      children: [
        for (var i = 0; i < cards.length; i++)
          AmbulanceReviewCard(
            title: trEn(f, ambulanceStepNames[i]),
            rows: cards[i],
            filipino: f,
            onEdit: () => onEdit(i),
          ),
      ],
    );
  }
}

/// One stop on the trip card: a ring (pickup) or a pin (destination) beside
/// its fields, with a rail from the pickup down to the destination.
class _RouteStop extends StatelessWidget {
  final bool isDestination;
  final Widget child;

  const _RouteStop({required this.isDestination, required this.child});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Container(
          margin: const EdgeInsets.only(left: 10),
          padding: const EdgeInsets.only(left: 20),
          decoration: isDestination
              ? null
              : const BoxDecoration(border: Border(left: BorderSide(color: AppColors.line, width: 2))),
          child: child,
        ),
        Positioned(
          left: isDestination ? 1 : 4,
          top: isDestination ? 30 : 14,
          child: isDestination
              ? const Icon(Icons.location_on_rounded, size: 20, color: AppColors.red600)
              : Container(
                  width: 14,
                  height: 14,
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    shape: BoxShape.circle,
                    border: Border.all(color: AppColors.green700, width: 3),
                  ),
                ),
        ),
      ],
    );
  }
}
