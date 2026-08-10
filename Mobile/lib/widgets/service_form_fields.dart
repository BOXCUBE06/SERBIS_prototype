import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import 'form_inputs.dart';

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

  const ServiceFormFields({super.key, required this.data, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return switch (data) {
      AmbulanceFormData form => Column(children: [
          AppTextField(
            label: 'Patient name',
            hint: 'e.g. Maria Santos',
            controller: form.patient,
          ),
          AppTextField(
            label: 'Pick-up location',
            hint: 'Purok / street, barangay',
            controller: form.pickup,
          ),
          AppTextField(
            label: 'Destination',
            hint: 'e.g. Echague District Hospital',
            controller: form.destination,
          ),
          AppTextField(
            label: 'Condition / notes',
            hint: "Briefly describe the patient's condition",
            lines: 3,
            controller: form.condition,
          ),
        ]),
      RoadFormData form => Column(children: [
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
          AppTextField(
            label: 'Description',
            hint: "Describe the obstruction and how it's affecting access",
            lines: 3,
            controller: form.description,
          ),
        ]),
      ReliefFormData form => Column(children: [
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
          AppDropdown(
            label: 'Type of assistance needed',
            items: ReliefFormData.assistanceTypes,
            value: form.assistance,
            onChanged: (v) {
              form.assistance = v;
              onChanged();
            },
          ),
        ]),
      GenericFormData form => Column(children: [
          AppTextField(
            label: 'Details',
            hint: 'Describe what you need and where',
            lines: 4,
            controller: form.details,
          ),
        ]),
    };
  }
}
