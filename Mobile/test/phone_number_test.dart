// The one rule for what a dialable number is, and the two screens that used to
// each have their own.
//
// The bug was not that any single rule was wrong in isolation — it was that
// three existed. register_screen accepted `1234567`, which the server rejects,
// so the form said yes and the submit 422'd. profile_screen accepted only
// `09…`, a strict subset, so a resident who registered with `+639…` could not
// save their own profile.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/phone_number.dart';
import 'package:serbis/widgets/form_inputs.dart';

void main() {
  group('the shape the server accepts', () {
    for (final number in <String>[
      '09171234567',
      '639171234567',
      '+639171234567',
    ]) {
      test('accepts $number', () {
        expect(PhoneNumber.isValid(number), isTrue);
      });
    }

    // Every one of these passed register_screen's old rule.
    for (final number in <String>[
      '1234567',
      '+1 555-0100',
      '0917 123 4567',
      '0917-123-4567',
    ]) {
      test('rejects $number, which the old register rule allowed', () {
        expect(PhoneNumber.isValid(number), isFalse);
      });
    }

    test('rejects a number that is too short or too long', () {
      expect(PhoneNumber.isValid('0917123456'), isFalse);
      expect(PhoneNumber.isValid('091712345678'), isFalse);
    });

    test('rejects a landline and a non-09 mobile prefix', () {
      expect(PhoneNumber.isValid('0781234567'), isFalse);
      expect(PhoneNumber.isValid('08171234567'), isFalse);
    });

    test('rejects letters and an empty value', () {
      expect(PhoneNumber.isValid('0917abcdefg'), isFalse);
      expect(PhoneNumber.isValid(''), isFalse);
      expect(PhoneNumber.isValid(null), isFalse);
    });

    test('tolerates the trailing space a keyboard adds after autocomplete', () {
      expect(PhoneNumber.isValid('  09171234567  '), isTrue);
    });
  });

  /// The whole point of the shared constant. If the backend's
  /// PhoneNumber::REGEX is ever edited, this is what says the app did not
  /// follow — the two live in different codebases and nothing else compares
  /// them.
  test('the pattern is the backend PhoneNumber::REGEX verbatim', () {
    expect(PhoneNumber.pattern.pattern, r'^(09\d{9}|639\d{9}|\+639\d{9})$');
  });

  test('maxLength is the longest shape the pattern can accept', () {
    expect(PhoneNumber.maxLength, '+639171234567'.length);
  });

  group('AppTextField.phone', () {
    Future<void> pumpField(
      WidgetTester tester,
      TextEditingController controller,
    ) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: AppTextField.phone(label: 'Mobile number', controller: controller),
        ),
      ));
    }

    testWidgets('lets the country-code shapes be typed', (tester) async {
      final controller = TextEditingController();
      addTearDown(controller.dispose);

      await pumpField(tester, controller);

      // Both were impossible before: `+` was stripped by digitsOnly and the
      // 12-digit form was cut off by maxLength 11.
      await tester.enterText(find.byType(TextField), '+639171234567');
      expect(controller.text, '+639171234567');

      await tester.enterText(find.byType(TextField), '639171234567');
      expect(controller.text, '639171234567');
    });

    testWidgets('still keeps letters and punctuation out', (tester) async {
      final controller = TextEditingController();
      addTearDown(controller.dispose);

      await pumpField(tester, controller);

      await tester.enterText(find.byType(TextField), '0917-abc 123');
      expect(controller.text, '0917123');
    });

    testWidgets('caps input at the longest valid shape', (tester) async {
      final controller = TextEditingController();
      addTearDown(controller.dispose);

      await pumpField(tester, controller);

      expect(
        tester.widget<AppTextField>(find.byType(AppTextField)).maxLength,
        PhoneNumber.maxLength,
      );
    });
  });
}
