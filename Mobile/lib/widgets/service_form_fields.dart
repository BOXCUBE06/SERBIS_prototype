import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'form_inputs.dart';
import 'form_section.dart';
import 'program_date_field.dart';

/// Renders the road, relief, generic and program forms. The ambulance has its
/// own stepped flow (ambulance_steps.dart).
///
/// Each field reads the same typed object the submit path reads, so a field
/// on screen and a field in the description cannot drift apart the way they
/// did while both sides went through a map of string keys.
///
/// [onChanged] fires when a dropdown moves: the value lives on [data], and the
/// screen has to rebuild to show it.
class ServiceFormFields extends StatelessWidget {
  final StructuredFormData data;
  final VoidCallback onChanged;
  final bool filipino;

  const ServiceFormFields({
    super.key,
    required this.data,
    required this.onChanged,
    required this.filipino,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final form = data;
    // One Column for the road, relief and generic forms. Each field renders
    // from its own spec entry — the same entry that decides the line it
    // contributes to the description — so a field cannot appear here and be
    // missing there.
    return Column(
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
    );
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
        borderRadius: BorderRadius.circular(AppRadius.sm),
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
              Expanded(child: Text(label, style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.ink))),
            ],
          ),
        ),
      ),
    );
  }
}
