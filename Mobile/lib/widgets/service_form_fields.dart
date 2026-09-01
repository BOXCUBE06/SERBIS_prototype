import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
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
                AppTextField(
                  label: 'Patient name',
                  hint: 'e.g. Maria Santos',
                  controller: form.patient,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.trip'),
              children: [
                AppTextField(
                  label: 'Address',
                  hint: 'Purok / street, barangay',
                  controller: form.pickup,
                ),
                AppTextField(
                  label: 'Destination',
                  hint: 'e.g. Echague District Hospital',
                  controller: form.destination,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.condition'),
              children: [
                AppTextField(
                  label: 'Condition / notes',
                  hint: "Briefly describe the patient's condition",
                  lines: 3,
                  controller: form.condition,
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
      RoadFormData form => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FormSection(
              label: tr(f, 'form_section.location'),
              children: [
                AppTextField(
                  label: 'Location / road name',
                  hint: 'e.g. Brgy. Malasin – Provincial Road',
                  controller: form.location,
                ),
                AppDropdown(
                  label: 'Obstruction type',
                  items: RoadFormData.obstructionTypes,
                  value: form.obstruction,
                  onChanged: (v) {
                    form.obstruction = v;
                    onChanged();
                  },
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.description'),
              children: [
                AppTextField(
                  label: 'Description',
                  hint: "Describe the obstruction and how it's affecting access",
                  lines: 3,
                  controller: form.description,
                ),
              ],
            ),
          ],
        ),
      ReliefFormData form => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FormSection(
              label: tr(f, 'form_section.household'),
              children: [
                AppTextField(
                  label: 'Household head name',
                  hint: 'Full name',
                  controller: form.head,
                ),
                AppTextField(
                  label: 'Address',
                  hint: 'Purok / street, barangay',
                  controller: form.address,
                ),
                AppTextField(
                  label: 'Household size',
                  hint: 'e.g. 5',
                  keyboard: TextInputType.number,
                  controller: form.householdSize,
                ),
              ],
            ),
            FormSection(
              label: tr(f, 'form_section.assistance'),
              children: [
                AppDropdown(
                  label: 'Type of assistance needed',
                  items: ReliefFormData.assistanceTypes,
                  value: form.assistance,
                  onChanged: (v) {
                    form.assistance = v;
                    onChanged();
                  },
                ),
              ],
            ),
          ],
        ),
      GenericFormData form => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FormSection(
              label: tr(f, 'form_section.details'),
              children: [
                AppTextField(
                  label: 'Details',
                  hint: 'Describe what you need and where',
                  lines: 4,
                  controller: form.details,
                ),
              ],
            ),
          ],
        ),
    };
  }
}
