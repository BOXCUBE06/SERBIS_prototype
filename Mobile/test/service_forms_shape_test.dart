// The exact block of text a dispatcher reads, pinned line for line.
//
// `description` is `metaLines.join('\n')` and the admin panel renders it one
// element per line, stripping the leading service name and the trailing
// `Contact:`/`Submitted` pair because its own header already shows all three
// (ServiceRequestQueue.vue, `descriptionLines`). So what is left of these
// lists after the first and the last two IS the request, as far as the office
// is concerned — the household size that decides how many food packs go out
// is one of them.
//
// Written before the road/relief/generic forms were collapsed onto one
// implementation, and passing unchanged after it. That is the whole point:
// the collapse was allowed to change how the forms are built and not one
// character of what they emit.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';

void main() {
  group('the road report', () {
    test('filled in', () {
      final form = StructuredFormData.road();
      addTearDown(form.dispose);

      form.field('location').text = 'Brgy. Malasin – Provincial Road';
      form.field('description').text = 'Half the lane is blocked';

      expect(
        form.metaLines(
          serviceName: 'Road Clearing',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'Road Clearing',
          'Brgy. Malasin – Provincial Road',
          'Obstruction: Fallen tree / branches',
          'Description: Half the lane is blocked',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('left blank', () {
      final form = StructuredFormData.road();
      addTearDown(form.dispose);

      expect(
        form.metaLines(
          serviceName: 'Road Clearing',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'Road Clearing',
          'Location not specified',
          'Obstruction: Fallen tree / branches',
          'Description: No description provided',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('carries no contact line at all', () {
      // Reported about a place, not about the reporter.
      final form = StructuredFormData.road();
      addTearDown(form.dispose);

      expect(
        form
            .metaLines(serviceName: 'Road Clearing', submittedLabel: 'x')
            .any((line) => line.startsWith('Contact:')),
        isFalse,
      );
    });
  });

  group('the relief request', () {
    test('filled in', () {
      final form = StructuredFormData.relief(
        headName: 'Maria Santos',
        contactNumber: '09171234567',
      );
      addTearDown(form.dispose);

      form.field('address').text = 'Purok 3, Silauan Norte';
      form.field('household_size').text = '5';

      expect(
        form.metaLines(
          serviceName: 'Relief Goods Distribution',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'Relief Goods Distribution',
          'Household head: Maria Santos',
          'Purok 3, Silauan Norte',
          'Household size: 5',
          'Assistance: Food packs',
          'Contact: 09171234567',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('household size survives as its own labelled line', () {
      // The number that decides how many food packs go out. It is a field with
      // a numeric keyboard and a line of its own — not something an operator
      // has to find inside a sentence.
      final form = StructuredFormData.relief(headName: 'Maria Santos');
      addTearDown(form.dispose);

      form.field('household_size').text = '7';

      expect(
        form.metaLines(serviceName: 'Relief Goods Distribution', submittedLabel: 'x'),
        contains('Household size: 7'),
      );
    });

    test('left blank', () {
      final form = StructuredFormData.relief();
      addTearDown(form.dispose);

      expect(
        form.metaLines(
          serviceName: 'Relief Goods Distribution',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'Relief Goods Distribution',
          'Household head: Not specified',
          'Address not specified',
          'Household size: Not specified',
          'Assistance: Food packs',
          'Contact: See resident profile',
          'Submitted Today, 9:00 AM',
        ],
      );
    });
  });

  group('the generic request', () {
    test('filled in', () {
      final form = StructuredFormData.generic(contactNumber: '09171234567');
      addTearDown(form.dispose);

      form.field('details').text = 'We need help clearing our yard';

      expect(
        form.metaLines(
          serviceName: 'General Inquiry',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'General Inquiry',
          'We need help clearing our yard',
          'Contact: 09171234567',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('left blank', () {
      final form = StructuredFormData.generic();
      addTearDown(form.dispose);

      expect(
        form.metaLines(
          serviceName: 'General Inquiry',
          submittedLabel: 'Today, 9:00 AM',
        ),
        [
          'General Inquiry',
          'No details provided',
          'Contact: See resident profile',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('whitespace is not a value', () {
      final form = StructuredFormData.generic();
      addTearDown(form.dispose);

      form.field('details').text = '   ';

      expect(
        form.metaLines(serviceName: 'General Inquiry', submittedLabel: 'x'),
        contains('No details provided'),
      );
    });
  });
}
