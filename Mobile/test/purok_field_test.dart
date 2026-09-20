// PurokField — a dropdown of the listed puroks plus a free-text "Other", the
// picker behind the purok/street field on register and profile (MDRRMO
// feedback, 2026-09-19). The value is plain text in the controller.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/widgets/purok_field.dart';

Future<void> _pump(WidgetTester tester, TextEditingController controller) async {
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(body: SingleChildScrollView(child: PurokField(controller: controller))),
  ));
  await tester.pumpAndSettle();
}

Future<void> _choose(WidgetTester tester, String label) async {
  await tester.tap(find.byType(DropdownButton<String>));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('offers Purok 1 to 6, Not specified and Other', (tester) async {
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    await tester.tap(find.byType(DropdownButton<String>));
    await tester.pumpAndSettle();

    for (var n = 1; n <= 6; n++) {
      expect(find.text('Purok $n'), findsOneWidget);
    }
    expect(find.text('Not specified'), findsWidgets);
    expect(find.text('Other (type it in)'), findsOneWidget);
    expect(find.text('Purok 7'), findsNothing);
  });

  testWidgets('choosing a purok stores that text and shows no text box', (tester) async {
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    await _choose(tester, 'Purok 3');

    expect(controller.text, 'Purok 3');
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('Other reveals a text box whose text is the stored value', (tester) async {
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    await _choose(tester, 'Other (type it in)');
    await tester.enterText(find.byType(TextField), 'Sitio Malaki');

    expect(controller.text, 'Sitio Malaki');
  });

  testWidgets('switching from a purok to Other starts with an empty box', (tester) async {
    final controller = TextEditingController(text: 'Purok 2');
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    await _choose(tester, 'Other (type it in)');

    expect(controller.text, isEmpty);
    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('a saved value that is not listed opens as Other with the text kept', (tester) async {
    final controller = TextEditingController(text: 'Zone 2, near the chapel');
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    expect(find.text('Other (type it in)'), findsOneWidget);
    expect(find.text('Zone 2, near the chapel'), findsOneWidget);
    expect(controller.text, 'Zone 2, near the chapel');
  });

  testWidgets('a saved listed purok opens selected', (tester) async {
    final controller = TextEditingController(text: 'Purok 5');
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    expect(find.text('Purok 5'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('Not specified clears the value', (tester) async {
    final controller = TextEditingController(text: 'Purok 5');
    addTearDown(controller.dispose);
    await _pump(tester, controller);

    await _choose(tester, 'Not specified');

    expect(controller.text, isEmpty);
  });
}
