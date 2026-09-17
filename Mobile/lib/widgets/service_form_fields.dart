import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'ambulance_schedule_field.dart';
import 'form_inputs.dart';
import 'form_section.dart';

/// Renders whichever of the four guided forms the resident picked.
///
/// Each branch reads the same typed object the submit path reads, so a field
/// on screen and a field in the description cannot drift apart the way they
/// did while both sides went through a map of string keys.
///
/// [onChanged] fires when a dropdown moves: the value lives on [data], and the
/// screen has to rebuild to show it.
class ServiceFormFields extends StatelessWidget {
  final ServiceFormData data;
  final VoidCallback onChanged;

  /// Only read by the ambulance branch, for [AmbulanceScheduleField]'s
  /// availability check — the other three forms have no use for either.
  final AppState appState;
  final bool filipino;

  const ServiceFormFields({
    super.key,
    required this.data,
    required this.onChanged,
    required this.appState,
    required this.filipino,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return switch (data) {
      AmbulanceFormData form => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FormSection(
              label: tr(f, 'form_section.patient'),
              children: [
                // Never prefilled — see AmbulanceFormData's constructor.
                AppTextField(
                  label: 'Patient name',
                  hint: 'e.g. Maria Santos',
                  controller: form.patient,
                ),
                AppTextField(
                  label: 'Age',
                  hint: 'e.g. 62',
                  keyboard: TextInputType.number,
                  controller: form.age,
                ),
                AppDropdown<String>(
                  label: 'Sex',
                  items: AmbulanceFormData.sexOptions,
                  value: form.sex,
                  onChanged: (v) {
                    form.sex = v;
                    onChanged();
                  },
                ),
                // Prefilled from the account and fully editable: the account
                // answers for the requester, and the patient may live
                // elsewhere.
                AppTextField(
                  label: 'Patient address',
                  hint: 'Purok / street, barangay',
                  controller: form.patientAddress,
                ),
                AppTextField.phone(
                  label: 'Contact number',
                  controller: form.patientContact,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.trip'),
              children: [
                AppTextField(
                  label: 'From',
                  hint: 'e.g. Purok 3, Brgy. Malasin',
                  controller: form.pickup,
                ),
                AppTextField(
                  label: 'To',
                  hint: 'e.g. Echague District Hospital',
                  controller: form.destination,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.condition'),
              children: [
                AppTextField(
                  label: 'Medical diagnosis',
                  hint: "Briefly describe the patient's condition",
                  lines: 3,
                  controller: form.diagnosis,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.relatives'),
              children: [
                // Composed from the same AppTextField every other row uses —
                // the repeater is layout around existing inputs, not a new
                // shared component.
                for (var i = 0; i < form.relatives.length; i++)
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: AppTextField(
                          label: 'Relative ${i + 1}',
                          hint: 'e.g. Juan Dela Cruz',
                          controller: form.relatives[i],
                        ),
                      ),
                      // Nudged down so it sits against the input rather than
                      // the label above it.
                      Padding(
                        padding: const EdgeInsets.only(top: 22, left: 4),
                        child: IconButton(
                          icon: const Icon(Icons.close_rounded, size: 20),
                          color: AppColors.inkFaint,
                          tooltip: 'Remove relative ${i + 1}',
                          onPressed: () {
                            form.removeRelative(i);
                            onChanged();
                          },
                        ),
                      ),
                    ],
                  ),
                if (form.relatives.length < AmbulanceFormData.maxRelatives)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () {
                        form.addRelative();
                        onChanged();
                      },
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: Text(
                        'Add relative',
                        style: AppText.display(size: 12, weight: FontWeight.w600),
                      ),
                      style: TextButton.styleFrom(
                        foregroundColor: AppColors.green600,
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        minimumSize: const Size(0, 36),
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                    ),
                  ),
              ],
            ),
            FormSection(
              label: tr(f, 'ambulance_schedule.title'),
              children: [
                AmbulanceScheduleField(
                  form: form,
                  appState: appState,
                  filipino: filipino,
                  onChanged: onChanged,
                ),
              ],
            ),
          ],
        ),
      // One branch for the road, relief and generic forms, which were three
      // near-identical Columns. Each field renders from its own spec entry —
      // the same entry that decides the line it contributes to the
      // description — so a field cannot appear here and be missing there.
      StructuredFormData form => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final section in form.spec.sections)
              FormSection(
                label: tr(f, section.labelKey),
                children: [
                  for (final field in section.fields)
                    if (field.isChoice)
                      AppDropdown(
                        label: field.label,
                        items: field.options,
                        value: form.choice(field.key),
                        onChanged: (v) {
                          form.select(field.key, v);
                          onChanged();
                        },
                      )
                    else
                      AppTextField(
                        label: field.label,
                        hint: field.hint,
                        lines: field.lines,
                        keyboard: field.keyboard,
                        controller: form.field(field.key),
                        helpText: field.helpText,
                      ),
                ],
              ),
          ],
        ),
    };
  }
}
