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

  /// The pickup landmark, drawn directly under the ambulance's From. Lives on the
  /// drafts (every form sends it), so it is passed in; the other forms still
  /// get it at the bottom from the request form.
  final TextEditingController? landmark;

  /// Barangay names for the patient address search. Empty leaves only
  /// "Other" (free text).
  final List<String> barangays;

  const ServiceFormFields({
    super.key,
    required this.data,
    required this.onChanged,
    required this.appState,
    required this.filipino,
    this.ambulanceDestinations = const [],
    this.landmark,
    this.barangays = const [],
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
                  label: trEn(f, 'Patient is myself'),
                  value: form.patientIsAccountHolder,
                  onChanged: (checked) {
                    form.setPatientIsAccountHolder(checked);
                    onChanged();
                  },
                ),
                AppTextField(
                  label: trEn(f, 'Patient name'),
                  hint: trEn(f, 'e.g. Maria Santos'),
                  controller: form.patient,
                ),
                AppTextField(
                  label: trEn(f, 'Age'),
                  hint: trEn(f, 'e.g. 62'),
                  keyboard: TextInputType.number,
                  controller: form.age,
                ),
                // Off by default — see AmbulanceFormData.setPatientAddressIsMyAddress.
                // The patient may live elsewhere, so this is a confirmation,
                // not an assumption.
                _CheckRow(
                  label: trEn(f, 'Same as my address'),
                  value: form.patientAddressIsMyAddress,
                  onChanged: (checked) {
                    form.setPatientAddressIsMyAddress(checked, barangays);
                    onChanged();
                  },
                ),
                ..._addressFields(
                  label: trEn(f, 'Patient address'),
                  barangay: form.patientBarangay,
                  onBarangay: (value) => form.patientBarangay = value,
                  field: form.patientAddress,
                ),
                AppTextField.phone(
                  label: trEn(f, 'Contact number'),
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
                  label: trEn(f, 'Same as my address'),
                  value: form.pickupIsMyAddress,
                  onChanged: (checked) {
                    form.setPickupIsMyAddress(checked);
                    onChanged();
                  },
                ),
                // Same list as To; "Other" is a free-text pickup location.
                AppSearchField(
                  label: trEn(f, 'From'),
                  hint: trEn(f, 'Search or pick Other'),
                  value: form.pickupChoice,
                  items: [...ambulanceDestinations, AmbulanceFormData.pickupOther],
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
                if (landmark != null)
                  AppTextField(
                    label: trEn(f, 'Landmark (optional)'),
                    hint: trEn(f, 'e.g. beside the chapel'),
                    controller: landmark!,
                  ),
                AppSearchField(
                  label: trEn(f, 'To'),
                  hint: trEn(f, 'Search or pick Others'),
                  value: form.destinationChoice,
                  items: [...ambulanceDestinations, AmbulanceFormData.destinationOthers],
                  itemLabel: (item) => trEn(f, item),
                  onChanged: (choice) {
                    form.setDestinationChoice(choice);
                    onChanged();
                  },
                ),
                if (form.destinationChoice == AmbulanceFormData.destinationOthers)
                  AppTextField(
                    label: trEn(f, 'Destination'),
                    hint: trEn(f, 'e.g. Echague District Hospital'),
                    controller: form.destination,
                  ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.condition'),
              children: [
                AppTextField(
                  label: trEn(f, 'Medical diagnosis'),
                  hint: trEn(f, "Briefly describe the patient's condition"),
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
                          label: i == 0 ? trEn(f, 'Relative 1 (required)') : trEn(f, 'Relative {n}').replaceAll('{n}', '${i + 1}'),
                          hint: trEn(f, 'e.g. Juan Dela Cruz'),
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
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () {
                        form.addRelative();
                        onChanged();
                      },
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: Text(
                        trEn(f, 'Add relative'),
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
                        label: trEn(f, 'Same as my address'),
                        value: form.addressIsMyAddress,
                        onChanged: (checked) {
                          form.setAddressIsMyAddress(checked);
                          onChanged();
                        },
                      ),
                    if (field.isDate)
                      ProgramDateField(
                        field: field,
                        filipino: f,
                        value: form.date(field.key),
                        onPicked: (picked) {
                          form.setDate(field.key, picked);
                          onChanged();
                        },
                      )
                    else if (field.isChoice)
                      AppDropdown(
                        label: trEn(f, field.label),
                        items: field.options,
                        itemLabel: (option) => trEn(f, option),
                        value: form.choice(field.key),
                        onChanged: (v) {
                          form.select(field.key, v);
                          onChanged();
                        },
                      )
                    else
                      AppTextField(
                        label: trEn(f, field.label),
                        hint: trEn(f, field.hint),
                        lines: field.lines,
                        keyboard: field.keyboard,
                        controller: form.field(field.key),
                        helpText: field.helpText == null ? null : trEn(f, field.helpText!),
                      ),
                  ],
                ],
              ),
            // Pickup/delivery beyond equipment borrowing (MDRRMO feedback,
            // 2026-09-18) — a real field on the request, not spec-driven
            // prose, so it lives outside the section loop above.
            if (form.offersFulfillment)
              FormSection(
                label: trEn(f, 'Pickup or delivery'),
                children: [
                  AppDropdown(
                    label: trEn(f, 'How should this reach you?'),
                    items: const ['Pickup', 'Delivery'],
                    itemLabel: (option) => trEn(f, option),
                    value: form.fulfillmentMethod,
                    onChanged: (v) {
                      form.fulfillmentMethod = v;
                      onChanged();
                    },
                  ),
                  if (form.fulfillmentMethod == 'Delivery')
                    AppTextField(
                      label: trEn(f, 'Delivery address'),
                      hint: trEn(f, 'Purok / street, barangay'),
                      controller: form.deliveryAddress,
                    ),
                ],
              ),
          ],
        ),
    };
  }

  /// Barangay search plus "Purok / street", or one free-text address field when
  /// "Other" is picked.
  List<Widget> _addressFields({
    required String label,
    required String? barangay,
    required ValueChanged<String?> onBarangay,
    required TextEditingController field,
  }) {
    final f = filipino;
    final other = barangay == AmbulanceFormData.barangayOther;
    return [
      AppSearchField(
        label: label,
        hint: trEn(f, 'Search barangay'),
        value: barangay,
        items: [...barangays, AmbulanceFormData.barangayOther],
        itemLabel: (item) => item == AmbulanceFormData.barangayOther ? trEn(f, item) : item,
        onChanged: (value) {
          onBarangay(value);
          onChanged();
        },
      ),
      other
          ? AppTextField(label: trEn(f, 'Full address'), hint: trEn(f, 'House no., street, barangay, town'), controller: field)
          : AppTextField(label: trEn(f, 'Purok / street'), hint: trEn(f, 'e.g. Purok 3'), controller: field),
    ];
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
