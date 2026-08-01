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
      expect(typed.length, 5);

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
      expect(typed.length, 4);

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
      expect(typed.length, 2);

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
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Maria Santos';
      form.contact.text = '09171234567';

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
    test('ambulance and relief require one; road and generic do not', () {
      final ambulance = AmbulanceFormData();
      final relief = ReliefFormData();
      final road = RoadFormData();
      final generic = GenericFormData();
      addTearDown(() {
        ambulance.dispose();
        relief.dispose();
        road.dispose();
        generic.dispose();
      });

      // Empty, not null: the screen rejects the submit on this.
      expect(ambulance.requiredContactNumber, '');
      expect(relief.requiredContactNumber, '');
      // Null means the form does not collect a callback number at all.
      expect(road.requiredContactNumber, isNull);
      expect(generic.requiredContactNumber, isNull);

      ambulance.contact.text = '09171234567';
      expect(ambulance.requiredContactNumber, '09171234567');
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

    testWidgets('the phone fields are numbers-only and capped at 11',
        (tester) async {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      await _pump(tester, form);

      await tester.enterText(find.byType(TextField).last, '0917-123-4567abc');

      expect(form.contact.text, '09171234567');
    });
  });
}
