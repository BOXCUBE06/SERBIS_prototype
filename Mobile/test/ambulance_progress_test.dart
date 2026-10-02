import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/service_forms.dart';

void main() {
  test('each ambulance section counts once it holds an answer', () {
    final form = AmbulanceFormData();
    addTearDown(form.dispose);

    // Only "When" is answered from the start: ASAP is the default.
    expect(form.sectionsDone(hasValidId: false), [false, false, false, false, true, false]);

    form.patient.text = 'Maria Santos';
    form.destination.text = 'Echague District Hospital';
    form.diagnosis.text = 'Cannot walk';
    form.relatives.first.text = 'Juan Dela Cruz';

    expect(form.sectionsDone(hasValidId: true), everyElement(isTrue));

    // Whitespace is not an answer.
    form.patient.text = '   ';
    expect(form.sectionsDone(hasValidId: true).first, isFalse);
  });
}
