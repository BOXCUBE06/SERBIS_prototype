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
import 'package:serbis/widgets/service_form_fields.dart';

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
        child: ServiceFormFields(data: data, onChanged: () {}),
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
      // Four, not five: the callback number came off this form and now comes
      // off the account. This count is the guard that catches a field rendered
      // but never read, so it moves deliberately.
      expect(typed.length, 4);

      final description = form
          .metaLines(serviceName: 'Ambulance', submittedLabel: 'Today, 9:00 AM')
          .join('\n');

      for (final value in typed) {
        expect(description, contains(value),
            reason: 'a field the resident filled in was dropped: $value');
      }
    });

    testWidgets('road obstruction', (tester) async {
      final form = RoadFormData();
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
      expect(description, contains(RoadFormData.obstructionTypes.first));
    });

    testWidgets('relief', (tester) async {
      final form = ReliefFormData();
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
      expect(description, contains(ReliefFormData.assistanceTypes.first));
    });

    testWidgets('generic', (tester) async {
      final form = GenericFormData();
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
        contains('Pick-up location not specified → destination not specified'),
      );
    });

    test('whitespace is not a value', () {
      final form = GenericFormData();
      addTearDown(form.dispose);
      form.details.text = '   ';

      expect(
        form.metaLines(serviceName: 'Inquiry', submittedLabel: 'x'),
        contains('No details provided'),
      );
    });
  });

  group('the contact number a dispatcher needs', () {
    test('comes off the account, on every form that carries one', () {
      final ambulance = AmbulanceFormData(contactNumber: '09171234567');
      final relief = ReliefFormData(contactNumber: '09171234567');
      final generic = GenericFormData(contactNumber: '09171234567');
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
      final road = RoadFormData();
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
      final form = RoadFormData();
      addTearDown(form.dispose);
      var changes = 0;

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: ServiceFormFields(data: form, onChanged: () => changes++),
        ),
      ));

      await tester.tap(find.byType(DropdownButton<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Landslide debris').last);
      await tester.pumpAndSettle();

      expect(form.obstruction, 'Landslide debris');
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
    final forms = <String, ServiceFormData Function()>{
      'the ambulance form': AmbulanceFormData.new,
      'the road form': RoadFormData.new,
      'the relief form': ReliefFormData.new,
      'the generic form': GenericFormData.new,
    };

    for (final entry in forms.entries) {
      testWidgets('${entry.key} asks for no callback number', (tester) async {
        final form = entry.value();
        addTearDown(form.dispose);
        await _pump(tester, form);

        expect(find.widgetWithText(TextField, '09XXXXXXXXX'), findsNothing);
      });
    }
  });
}
