import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';

void main() {
  test('the ambulance form is dirty only once the resident enters something', () {
    final form = AmbulanceFormData(contactNumber: '09171234567');
    addTearDown(form.dispose);

    // The prefilled contact alone is not an answer.
    expect(form.isDirty, isFalse);

    form.patient.text = '   ';
    expect(form.isDirty, isFalse, reason: 'whitespace is not an answer');

    form.relatives.first.text = 'Juan Dela Cruz';
    expect(form.isDirty, isTrue);

    form.relatives.first.clear();
    form.patientContact.text = '09170000000';
    expect(form.isDirty, isTrue, reason: 'an edited contact counts');

    form.patientContact.text = '09171234567';
    form.scheduledAt = DateTime(2026, 10, 5, 9);
    expect(form.isDirty, isTrue);
  });
}
