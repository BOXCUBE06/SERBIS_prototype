import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'ambulance_schedule_field.dart';
import 'form_inputs.dart';
import 'form_section.dart';
import 'program_date_field.dart';

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

  /// The seeded destination list for [AmbulanceFormData.destinationChoice]
  /// (MDRRMO feedback, 2026-09-19). Empty is a valid state — the dropdown
  /// then offers only "Others", same as before this list existed.
  final List<String> ambulanceDestinations;

  /// The pickup landmark, drawn under the ambulance's From/To. Lives on the
  /// drafts (every form sends it), so it is passed in; the other forms still
  /// get it at the bottom from the request form.
  final TextEditingController? landmark;

  const ServiceFormFields({
    super.key,
    required this.data,
    required this.onChanged,
    required this.appState,
    required this.filipino,
    this.ambulanceDestinations = const [],
    this.landmark,
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
                // Off by default — see AmbulanceFormData.setPatientIsAccountHolder.
                // Checking it fills the name below once; the field stays fully
                // editable either way.
                _CheckRow(
                  label: 'Patient is myself',
                  value: form.patientIsAccountHolder,
                  onChanged: (checked) {
                    form.setPatientIsAccountHolder(checked);
                    onChanged();
                  },
                ),
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
                // Off by default — see AmbulanceFormData.setPatientAddressIsMyAddress.
                // The patient may live elsewhere, so this is a confirmation,
                // not an assumption.
                _CheckRow(
                  label: 'Same as my address',
                  value: form.patientAddressIsMyAddress,
                  onChanged: (checked) {
                    form.setPatientAddressIsMyAddress(checked);
                    onChanged();
                  },
                ),
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
                // Off by default — see AmbulanceFormData.setPickupIsMyAddress.
                // Separate from the patient-address checkbox above: the
                // pickup point and the patient's address are often the same,
                // but not always.
                _CheckRow(
                  label: 'Same as my address',
                  value: form.pickupIsMyAddress,
                  onChanged: (checked) {
                    form.setPickupIsMyAddress(checked);
                    onChanged();
                  },
                ),
                AppTextField(
                  label: 'From',
                  hint: 'e.g. Purok 3, Brgy. Malasin',
                  controller: form.pickup,
                ),
                AppDropdown<String>(
                  label: 'To',
                  value: form.destinationChoice,
                  items: [...ambulanceDestinations, AmbulanceFormData.destinationOthers],
                  onChanged: (choice) {
                    form.setDestinationChoice(choice);
                    onChanged();
                  },
                ),
                if (form.destinationChoice == AmbulanceFormData.destinationOthers)
                  AppTextField(
                    label: 'Destination',
                    hint: 'e.g. Echague District Hospital',
                    controller: form.destination,
                  ),
                if (landmark != null)
                  AppTextField(
                    label: 'Landmark (optional)',
                    hint: 'e.g. beside the chapel',
                    controller: landmark!,
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
                          label: i == 0 ? 'Relative 1 (required)' : 'Relative ${i + 1}',
                          hint: 'e.g. Juan Dela Cruz',
                          controller: form.relatives[i],
                        ),
                      ),
                      // Nudged down so it sits against the input rather than
                      // the label above it. Not on a lone row: removing the only
                      // relative just blanks it, and the X narrowed the field.
                      if (form.relatives.length > 1)
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
                        foregroundColor: AppColors.green700,
                        padding: const EdgeInsets.only(right: 8),
                        minimumSize: const Size(0, 44),
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
                  for (final field in section.fields) ...[
                    // Off by default — see StructuredFormData.setAddressIsMyAddress.
                    // Relief goods are often requested for somewhere other
                    // than the account holder's own address, so this is a
                    // confirmation, not an assumption.
                    if (field.key == 'address' && form.hasAddressField)
                      _CheckRow(
                        label: 'Same as my address',
                        value: form.addressIsMyAddress,
                        onChanged: (checked) {
                          form.setAddressIsMyAddress(checked);
                          onChanged();
                        },
                      ),
                    if (field.isDate)
                      ProgramDateField(
                        field: field,
                        value: form.date(field.key),
                        onPicked: (picked) {
                          form.setDate(field.key, picked);
                          onChanged();
                        },
                      )
                    else if (field.isChoice)
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
                ],
              ),
            // Pickup/delivery beyond equipment borrowing (MDRRMO feedback,
            // 2026-09-18) — a real field on the request, not spec-driven
            // prose, so it lives outside the section loop above.
            if (form.offersFulfillment)
              FormSection(
                label: 'Pickup or delivery',
                children: [
                  AppDropdown(
                    label: 'How should this reach you?',
                    items: const ['Pickup', 'Delivery'],
                    value: form.fulfillmentMethod,
                    onChanged: (v) {
                      form.fulfillmentMethod = v;
                      onChanged();
                    },
                  ),
                  if (form.fulfillmentMethod == 'Delivery')
                    AppTextField(
                      label: 'Delivery address',
                      hint: 'Purok / street, barangay',
                      controller: form.deliveryAddress,
                    ),
                ],
              ),
          ],
        ),
    };
  }
}

/// "Same as my address"-style shortcut. Was a [CheckboxListTile], whose own
/// padding pushed the box ~12dp in from the field edge below it and left the
/// label floating mid-row. The box here lines up with the inputs, and the whole
/// row (44dp tall) is the tap target.
class _CheckRow extends StatelessWidget {
  final String label;
  final bool value;
  final ValueChanged<bool> onChanged;

  const _CheckRow({required this.label, required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: InkWell(
        onTap: () => onChanged(!value),
        borderRadius: BorderRadius.circular(8),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 44),
          child: Row(
            children: [
              SizedBox(
                width: 24,
                height: 24,
                child: Checkbox(
                  value: value,
                  onChanged: (checked) => onChanged(checked ?? false),
                  materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  visualDensity: const VisualDensity(horizontal: -4, vertical: -4),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(child: Text(label, style: AppText.body(size: 14, color: AppColors.ink))),
            ],
          ),
        ),
      ),
    );
  }
}
