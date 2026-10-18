// The field rules shared with the server (App\Rules\Charset / FieldRules) and
// the admin panel (inputRules.ts). If one side changes, this is where the app
// notices it has not followed.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/validators.dart';

/// What a field ends up holding after `text` is typed or pasted into it.
String _typed(FieldKind kind, String text) {
  var value = TextEditingValue(text: text);
  for (final formatter in Validators.formatters(kind)) {
    value = formatter.formatEditUpdate(const TextEditingValue(), value);
  }
  return value.text;
}

void main() {
  test('a name takes letters incl. ñ, spaces, hyphen, apostrophe and period, no digits', () {
    expect(_typed(FieldKind.name, "Ma. Niña O'Brien-Cruz 3"), "Ma. Niña O'Brien-Cruz ");
    expect(Validators.check(FieldKind.name, 'Niña', label: 'Name'), isNull);
    expect(Validators.check(FieldKind.name, 'J', label: 'Name'), 'Name must be at least 2 letters.');
    expect(Validators.check(FieldKind.name, 'Juan3', label: 'Name'),
        'Name can only contain letters, spaces, hyphens, apostrophes and periods.');
  });

  test('a phone is 11 digits starting 09', () {
    expect(_typed(FieldKind.phone, '0917-123 4567x9'), '09171234567');
    expect(Validators.check(FieldKind.phone, '09171234567', label: 'Contact number'), isNull);
    expect(Validators.check(FieldKind.phone, '19171234567', label: 'Contact number'),
        'Contact number must be 11 digits and start with 09.');
  });

  test('an age is a whole number from 0 to 120', () {
    expect(_typed(FieldKind.age, '12ab34'), '123');
    expect(Validators.check(FieldKind.age, '0', label: 'Age'), isNull);
    expect(Validators.check(FieldKind.age, '120', label: 'Age'), isNull);
    expect(Validators.check(FieldKind.age, '121', label: 'Age'), 'Age must be a whole number from 0 to 120.');
  });

  test('an address takes letters, numbers, spaces and , . - # /', () {
    expect(_typed(FieldKind.address, 'Purok 3, #12/B <b>@'), 'Purok 3, #12/B b');
    expect(Validators.check(FieldKind.address, 'Purok 3@', label: 'Address'),
        'Address can only contain letters, numbers, spaces and , . - # /');
  });

  test('a note drops tags and caps at 500', () {
    expect(_typed(FieldKind.note, 'Fall <script>x'), 'Fall scriptx');
    expect(Validators.check(FieldKind.note, 'a' * 501, label: 'Condition'), 'Condition must be 500 characters or fewer.');
  });

  test('blank passes unless required', () {
    expect(Validators.check(FieldKind.name, '  ', label: 'Name'), isNull);
    expect(Validators.check(FieldKind.name, '  ', label: 'Name', required: true), 'Name is required.');
  });
}
