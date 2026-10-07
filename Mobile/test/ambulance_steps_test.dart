// The ambulance flow's five steps (C_Amb1-5) at a 320px phone, in English and
// Filipino, each with its header and footer and with the step's error showing
// where it has one — the longest each step gets.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/ambulance_steps.dart';
import 'package:serbis/widgets/form_steps.dart' show ReviewList;

/// The one check each step runs, by the key its error is filed under.
const _stepErrors = <Map<String, String>>[
  {'patient': 'Enter the patient name.'},
  {'destination': 'Enter where the ambulance should go.'},
  {'relative': 'Name at least one relative going with the patient.'},
  {'validId': 'Attach a photo of a valid ID.'},
  {},
];

Future<void> _pumpStep(WidgetTester tester, int step, {required bool filipino}) async {
  tester.view.physicalSize = const Size(320, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final state = AppState(ApiService());
  if (filipino) state.setLanguage(AppLanguage.filipino);
  final form = AmbulanceFormData();
  addTearDown(form.dispose);
  final landmark = TextEditingController(text: 'Beside the chapel near the barangay hall');
  addTearDown(landmark.dispose);
  // Long answers, so the Review rows wrap the way a real request's do.
  form.patient.text = 'Maria Santos dela Cruz-Villanueva';
  form.age.text = '62';
  form.patientContact.text = '09170000101';
  form.destination.text = 'Echague District Hospital, Brgy. San Fabian';
  form.diagnosis.text = 'Weakness and dizziness since this morning; can walk with help';
  form.relatives.first.text = 'Lalaine Ferrer';

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: Column(
        children: [
          AmbulanceStepHeader(step: step, filipino: filipino, onClose: () {}),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(AppLayout.gutter),
              children: [
                AmbulanceStepFields(
                  step: step,
                  form: form,
                  appState: state,
                  filipino: filipino,
                  onChanged: () {},
                  destinations: const ['Echague District Hospital'],
                  barangays: const ['San Fabian'],
                  landmark: landmark,
                  errors: _stepErrors[step],
                  validIdName: step == 4 ? 'national-id-front-photo.jpg' : null,
                  onTakePhoto: () {},
                  onChooseFile: () {},
                  onEdit: (_) {},
                ),
              ],
            ),
          ),
          AmbulanceStepFooter(step: step, filipino: filipino, submitting: false, onBack: () {}, onNext: () {}),
        ],
      ),
    ),
  ));
  await tester.pump();
}

void main() {
  for (var step = 0; step < 5; step++) {
    for (final filipino in [false, true]) {
      testWidgets('step ${step + 1} fits 320px in ${filipino ? 'Filipino' : 'English'} without overflow', (tester) async {
        await _pumpStep(tester, step, filipino: filipino);

        expect(tester.takeException(), isNull);
        // The step drawn is the one asked for, in the language asked for.
        final stepName = ['Patient', 'Trip', 'Condition', 'Schedule and ID', 'Review'][step];
        if (!filipino) expect(find.textContaining('Step ${step + 1} of 5 · $stepName'), findsOneWidget);
        if (filipino) expect(find.textContaining('Hakbang ${step + 1} sa 5'), findsOneWidget);
      });
    }
  }

  testWidgets('a refused step names its field above the form', (tester) async {
    await _pumpStep(tester, 0, filipino: false);

    expect(find.textContaining('Please check: Full name'), findsOneWidget);
    expect(find.text('Enter the patient name.'), findsOneWidget);
  });

  testWidgets('Review is one list with an Edit per row', (tester) async {
    await _pumpStep(tester, 4, filipino: false);

    expect(find.byType(ReviewList), findsOneWidget);
    expect(find.text('Edit'), findsNWidgets(5));
    expect(find.text('national-id-front-photo.jpg'), findsOneWidget);
  });
}
