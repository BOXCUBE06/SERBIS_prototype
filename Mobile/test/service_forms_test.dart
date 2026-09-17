// M27: the four guided forms shared one `Map<String, TextEditingController>`
// keyed by strings like 'amb_patient'. `_ctrl(key)` created a controller on
// demand, so a field could be rendered, typed into, and never read on the way
// out with nothing to flag it — which is exactly what M2 was.
//
// The forms are typed objects now. The tests that matter here are not "does
// metaLines format nicely" but "is every field the resident can fill in
// actually sent": each form is pumped, every input on screen is filled with a
// distinct value, and the description is checked for all of them.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/service_form_fields.dart';

/// Only the ambulance form's schedule field ever reaches this — the others
/// never touch AppState — and this test never taps it, so a throwaway
/// instance with no network is enough.
final AppState _appState = AppState(ApiService());

/// Fills every field on screen with a distinct value and returns them in
/// order. The values are 11 digits because the contact fields strip everything
/// else and cap at 11 — a word typed there would come back as one character
/// and the check would prove nothing.
Future<List<String>> _fillEveryField(WidgetTester tester) async {
  final fields = find.byType(TextField);
  final typed = <String>[];

  for (var i = 0; i < tester.widgetList(fields).length; i++) {
    final value = '0917000000$i';
    await tester.enterText(fields.at(i), value);
    typed.add(value);
  }

  await tester.pump();
  return typed;
}

Future<void> _pump(WidgetTester tester, ServiceFormData data) async {
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: ServiceFormFields(
          data: data,
          onChanged: () {},
          appState: _appState,
          filipino: false,
        ),
      ),
    ),
  ));
}

void main() {
  group('every field on screen reaches the dispatcher', () {
    testWidgets('ambulance', (tester) async {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      await _pump(tester, form);

      final typed = await _fillEveryField(tester);
      // Eight, up from four: the structured rebuild added age, patient
      // address, patient contact number and one relative slot alongside the
      // original patient / from / to / diagnosis. Sex is not counted — it is
      // an AppDropdown, not a TextField, and is asserted separately below.
      //
      // This count is the guard that catches a field rendered but never read,
      // so it moves deliberately and never to make a run go green.
      expect(typed.length, 8);

      final description = form
          .metaLines(serviceName: 'Ambulance', submittedLabel: 'Today, 9:00 AM')
          .join('\n');

      for (final value in typed) {
        expect(description, contains(value),
            reason: 'a field the resident filled in was dropped: $value');
      }
    });

    testWidgets('ambulance — the sex dropdown reaches the description too',
        (tester) async {
      // The one input on this form that is not a TextField, so
      // _fillEveryField above cannot cover it.
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      await _pump(tester, form);

      await tester.tap(find.byType(DropdownButton<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Female').last);
      await tester.pumpAndSettle();

      expect(form.sex, 'Female');
      // Lowercased on the way to the API, which takes `in:male,female`.
      expect(form.sexValue, 'female');
      expect(
        form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x'),
        contains('Sex: female'),
      );
    });

    testWidgets('road obstruction', (tester) async {
      final form = StructuredFormData.road();
      addTearDown(form.dispose);
      await _pump(tester, form);

      final typed = await _fillEveryField(tester);
      expect(typed.length, 2);

      final description = form
          .metaLines(serviceName: 'Road clearing', submittedLabel: 'Today, 9:00 AM')
          .join('\n');

      for (final value in typed) {
        expect(description, contains(value), reason: 'dropped: $value');
      }
      // The dropdown is a field too, and its default is a real answer.
      expect(description, contains(kObstructionTypes.first));
    });

    testWidgets('relief', (tester) async {
      final form = StructuredFormData.relief();
      addTearDown(form.dispose);
      await _pump(tester, form);

      final typed = await _fillEveryField(tester);
      expect(typed.length, 3);

      final description = form
          .metaLines(serviceName: 'Relief goods', submittedLabel: 'Today, 9:00 AM')
          .join('\n');

      for (final value in typed) {
        expect(description, contains(value), reason: 'dropped: $value');
      }
      expect(description, contains(kAssistanceTypes.first));
    });

    testWidgets('generic', (tester) async {
      final form = StructuredFormData.generic();
      addTearDown(form.dispose);
      await _pump(tester, form);

      final typed = await _fillEveryField(tester);
      expect(typed.length, 1);

      final description = form
          .metaLines(serviceName: 'Information inquiry', submittedLabel: 'Today, 9:00 AM')
          .join('\n');

      for (final value in typed) {
        expect(description, contains(value), reason: 'dropped: $value');
      }
    });
  });

  group('the description keeps the shape the dispatcher reads', () {
    test('the service name leads and the submission time closes', () {
      final form = AmbulanceFormData(contactNumber: '09171234567');
      addTearDown(form.dispose);
      form.patient.text = 'Maria Santos';

      final lines = form.metaLines(
        serviceName: 'Ambulance',
        submittedLabel: 'Today, 9:00 AM',
      );

      expect(lines.first, 'Ambulance');
      expect(lines.last, 'Submitted Today, 9:00 AM');
      expect(lines, contains('Patient: Maria Santos'));
      expect(lines, contains('Contact: 09171234567'));
    });

    test('a blank optional field says so rather than leaving a gap', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      final lines = form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x');

      expect(lines, contains('Patient: Not specified'));
      expect(lines, contains('Condition: Not described'));
      expect(
        lines,
        contains('Address not specified → destination not specified'),
      );
    });

    test('whitespace is not a value', () {
      final form = StructuredFormData.generic();
      addTearDown(form.dispose);
      form.field('details').text = '   ';

      expect(
        form.metaLines(serviceName: 'Inquiry', submittedLabel: 'x'),
        contains('No details provided'),
      );
    });
  });

  group('what the account fills in for the resident', () {
    test('the address and the contact number are prefilled, the name is not', () {
      final form = AmbulanceFormData(
        contactNumber: '09171234567',
        accountAddress: 'San Fabian',
      );
      addTearDown(form.dispose);

      expect(form.patientAddress.text, 'San Fabian');
      expect(form.patientContact.text, '09171234567');
      // The one field that must never arrive pre-answered: the requester is
      // often not the patient, and a name already in the box gets submitted
      // unchecked.
      expect(form.patient.text, isEmpty);
    });

    test('every other field starts blank', () {
      final form = AmbulanceFormData(
        contactNumber: '09171234567',
        accountAddress: 'San Fabian',
      );
      addTearDown(form.dispose);

      expect(form.age.text, isEmpty);
      expect(form.pickup.text, isEmpty);
      expect(form.destination.text, isEmpty);
      expect(form.diagnosis.text, isEmpty);
      expect(form.sex, AmbulanceFormData.sexUnspecified);
      expect(form.sexValue, isNull);
      expect(form.relativeNames, isEmpty);
      expect(form.scheduledAt, isNull);
    });

    test('an edited prefill is what reaches the dispatcher, not the account', () {
      final form = AmbulanceFormData(
        contactNumber: '09171234567',
        accountAddress: 'San Fabian',
      );
      addTearDown(form.dispose);

      form.patientAddress.text = 'Purok 7, San Miguel';
      form.patientContact.text = '09189999999';

      final lines = form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x');

      expect(lines, contains('Address: Purok 7, San Miguel'));
      expect(lines, contains('Contact: 09189999999'));
      expect(lines.join('\n'), isNot(contains('San Fabian')));
      expect(lines.join('\n'), isNot(contains('09171234567')));
    });
  });

  group('the relatives repeater', () {
    test('starts with exactly one empty slot', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      expect(form.relatives, hasLength(1));
      expect(form.relativeNames, isEmpty);
    });

    test('adds rows and reports the names in order', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      form.relatives[0].text = 'Lalaine Ferrer';
      form.addRelative();
      form.relatives[1].text = 'Rosa Dela Cruz';

      expect(form.relativeNames, ['Lalaine Ferrer', 'Rosa Dela Cruz']);
      expect(
        form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x'),
        contains('Relatives: Lalaine Ferrer, Rosa Dela Cruz'),
      );
    });

    test('caps at two and refuses to add a third slot', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      form.addRelative();
      form.addRelative();

      expect(form.relatives, hasLength(2));
    });

    test('blank and whitespace-only slots are dropped, not sent', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      form.relatives[0].text = '   ';
      form.addRelative();
      form.relatives[1].text = 'Lalaine Ferrer';
      form.addRelative();

      expect(form.relativeNames, ['Lalaine Ferrer']);
    });

    test('removing a row drops that name', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      form.relatives[0].text = 'Lalaine Ferrer';
      form.addRelative();
      form.relatives[1].text = 'Rosa Dela Cruz';

      form.removeRelative(0);

      expect(form.relativeNames, ['Rosa Dela Cruz']);
    });

    test('removing the last row leaves one empty slot, never none', () {
      // An empty repeater reads as a broken section rather than an optional
      // one, and "Add relative" becomes the only way back to the starting
      // state.
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      form.relatives[0].text = 'Lalaine Ferrer';
      form.removeRelative(0);

      expect(form.relatives, hasLength(1));
      expect(form.relatives.single.text, isEmpty);
      expect(form.relativeNames, isEmpty);
    });

    test('no relatives means no Relatives line at all', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      expect(
        form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x')
            .any((line) => line.startsWith('Relatives:')),
        isFalse,
      );
    });
  });

  group('the contact number a dispatcher needs', () {
    test('comes off the account, on every form that carries one', () {
      final ambulance = AmbulanceFormData(contactNumber: '09171234567');
      final relief = StructuredFormData.relief(contactNumber: '09171234567');
      final generic = StructuredFormData.generic(contactNumber: '09171234567');
      addTearDown(() {
        ambulance.dispose();
        relief.dispose();
        generic.dispose();
      });

      for (final form in [ambulance, relief, generic]) {
        expect(
          form.metaLines(serviceName: 'x', submittedLabel: 'y'),
          contains('Contact: 09171234567'),
          reason: '${form.runtimeType} dropped the account number',
        );
      }
    });

    test('a road report still carries no number at all', () {
      // Reported about a place, not about the reporter. Adding one here would
      // be a change of meaning, not a fix.
      final road = StructuredFormData.road();
      addTearDown(road.dispose);

      expect(
        road.metaLines(serviceName: 'Road clearing', submittedLabel: 'x')
            .any((line) => line.startsWith('Contact:')),
        isFalse,
      );
    });

    test('an account with no number on file says where to look', () {
      // `phone_number` is required at registration and NOT NULL, so this is
      // only reachable before the profile has loaded. It must not read as a
      // number the dispatcher can dial.
      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      expect(
        form.metaLines(serviceName: 'Ambulance', submittedLabel: 'x'),
        contains('Contact: See resident profile'),
      );
    });
  });

  group('the form widget', () {
    testWidgets('a dropdown change lands on the model and is reported',
        (tester) async {
      final form = StructuredFormData.road();
      addTearDown(form.dispose);
      var changes = 0;

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: ServiceFormFields(
            data: form,
            onChanged: () => changes++,
            appState: _appState,
            filipino: false,
          ),
        ),
      ));

      await tester.tap(find.byType(DropdownButton<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Landslide debris').last);
      await tester.pumpAndSettle();

      expect(form.choice('obstruction'), 'Landslide debris');
      // Without the callback the screen never rebuilds and the dropdown reads
      // as though nothing was picked.
      expect(changes, 1);
      expect(
        form.metaLines(serviceName: 'Road clearing', submittedLabel: 'x'),
        contains('Obstruction: Landslide debris'),
      );
    });

    // One test per form, not a loop inside one: `pumpWidget` twice in a single
    // test updates the existing element rather than building a new one, so the
    // previous form's subtree is what the second assertion would be reading.
    //
    // The number is on the account from registration. A form that still
    // rendered the field would be asking a resident to retype what MDRRMO
    // already holds, in an emergency.
    //
    // The ambulance form is deliberately not in this list any more. It carries
    // a phone field again, but it is not the callback number this group is
    // about: it is `patient_contact_number`, the number to ring about the
    // patient, who is often not the account holder. It arrives prefilled with
    // the account's number, so it still asks nobody to retype anything — see
    // the test below.
    final forms = <String, ServiceFormData Function()>{
      'the road form': StructuredFormData.road,
      'the relief form': StructuredFormData.relief,
      'the generic form': StructuredFormData.generic,
    };

    for (final entry in forms.entries) {
      testWidgets('${entry.key} asks for no callback number', (tester) async {
        final form = entry.value();
        addTearDown(form.dispose);
        await _pump(tester, form);

        expect(find.widgetWithText(TextField, '09XXXXXXXXX'), findsNothing);
      });
    }

    testWidgets(
        "the ambulance form's number field is the patient's, and starts prefilled",
        (tester) async {
      final form = AmbulanceFormData(
        contactNumber: '09171234567',
        accountAddress: 'San Fabian',
      );
      addTearDown(form.dispose);
      await _pump(tester, form);

      // Present, unlike the three above — but never blank, so it is a value to
      // correct rather than a question to answer.
      expect(find.widgetWithText(TextField, '09XXXXXXXXX'), findsOneWidget);
      expect(find.widgetWithText(TextField, '09171234567'), findsOneWidget);
    });

    testWidgets('the ambulance form lays out at real phone widths',
        (tester) async {
      // The relatives repeater is the one new row that puts a field and a
      // button side by side, and a Row without Expanded is exactly how this
      // codebase has produced clipped, non-hit-testable widgets before. A
      // clipped half stops responding to taps and only warns, so this asserts
      // the absence of overflow rather than trusting the tall synthetic
      // surface the other tests use.
      //
      // 320 is the narrowest width worth supporting; the test font is wider
      // than the real typeface, so passing here is stricter than the device.
      for (final width in const [320.0, 360.0, 430.0]) {
        tester.view.physicalSize = Size(width * 3, 8000);
        tester.view.devicePixelRatio = 3;

        final form = AmbulanceFormData(
          contactNumber: '09171234567',
          accountAddress: 'Purok 3, San Fabian',
        );
        form.addRelative();

        await _pump(tester, form);
        await tester.pumpAndSettle();

        expect(
          tester.takeException(),
          isNull,
          reason: 'the ambulance form overflowed at ${width}px',
        );

        form.dispose();
      }

      tester.view.reset();
    });

    testWidgets('the ambulance form renders every structured field',
        (tester) async {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      await _pump(tester, form);

      for (final label in const [
        'Patient name',
        'Age',
        'Sex',
        'Patient address',
        'Contact number',
        'From',
        'To',
        'Medical diagnosis',
        'Relative 1',
      ]) {
        expect(find.text(label), findsOneWidget,
            reason: '$label is missing from the ambulance form');
      }
    });

    testWidgets('the relatives repeater adds and removes rows on screen',
        (tester) async {
      // The default 800x600 surface leaves this form's lower half off screen,
      // and a tap on an off-screen target only warns — the row is never added
      // and the failure reads as "the repeater does not work". Tall surface
      // plus ensureVisible, so neither depends on the other.
      tester.view.physicalSize = const Size(1080, 8000);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);

      final form = AmbulanceFormData();
      addTearDown(form.dispose);

      // Pumped through a StatefulBuilder so onChanged actually rebuilds, the
      // way the services screen's setState does — without it the repeater
      // mutates the model and the screen never shows it.
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: StatefulBuilder(
            builder: (context, setState) => SingleChildScrollView(
              child: ServiceFormFields(
                data: form,
                onChanged: () => setState(() {}),
                appState: _appState,
                filipino: false,
              ),
            ),
          ),
        ),
      ));

      expect(find.text('Relative 1'), findsOneWidget);
      expect(find.text('Relative 2'), findsNothing);

      await tester.ensureVisible(find.text('Add relative'));
      await tester.tap(find.text('Add relative'));
      await tester.pumpAndSettle();
      expect(find.text('Relative 2'), findsOneWidget);

      // Cap of two reached: the button that would add a third row is gone.
      expect(find.text('Add relative'), findsNothing);

      await tester.enterText(
        find.descendant(
          of: find.byWidgetPredicate(
              (w) => w is AppTextField && w.label == 'Relative 2'),
          matching: find.byType(TextField),
        ),
        'Rosa Dela Cruz',
      );
      await tester.pump();
      expect(form.relativeNames, ['Rosa Dela Cruz']);

      await tester.ensureVisible(find.byTooltip('Remove relative 2'));
      await tester.tap(find.byTooltip('Remove relative 2'));
      await tester.pumpAndSettle();

      expect(find.text('Relative 2'), findsNothing);
      expect(form.relativeNames, isEmpty);
    });
  });
}
