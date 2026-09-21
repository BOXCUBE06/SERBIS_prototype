import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/service_widgets.dart';

Future<void> _pump(WidgetTester tester, {required bool collapsible, bool filipino = false}) async {
  tester.view.physicalSize = const Size(1080, 4000);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: SingleChildScrollView(
        child: SafetyNotice(filipino: filipino, collapsible: collapsible),
      ),
    ),
  ));
}

String _firstNumber() {
  final n = kHotlines.first.numbers.first;
  return n.label == null ? n.number : '${n.label} · ${n.number}';
}

void main() {
  testWidgets('by default the numbers are all on show', (tester) async {
    await _pump(tester, collapsible: false);

    expect(find.text('Non-life-threatening use only'), findsOneWidget);
    expect(find.text(_firstNumber()), findsOneWidget);
    expect(find.text('Show hotline numbers'), findsNothing);
  });

  testWidgets('above a form the numbers fold away, the warning stays', (tester) async {
    await _pump(tester, collapsible: true);

    expect(find.text('Non-life-threatening use only'), findsOneWidget);
    expect(find.textContaining('life-threatening emergency'), findsOneWidget);
    expect(find.text('Show hotline numbers'), findsOneWidget);
    expect(find.text(_firstNumber()), findsNothing);
  });

  testWidgets('tapping the row opens the numbers and tapping again folds them', (tester) async {
    await _pump(tester, collapsible: true);

    await tester.tap(find.text('Show hotline numbers'));
    await tester.pumpAndSettle();
    expect(find.text(_firstNumber()), findsOneWidget);
    expect(find.text('Hide hotline numbers'), findsOneWidget);

    await tester.tap(find.text('Hide hotline numbers'));
    await tester.pumpAndSettle();
    expect(find.text(_firstNumber()), findsNothing);
  });

  testWidgets('the toggle row is a 44dp target and is translated', (tester) async {
    await _pump(tester, collapsible: true, filipino: true);

    expect(find.text('Ipakita ang mga numero ng hotline'), findsOneWidget);
    final row = find.ancestor(
      of: find.text('Ipakita ang mga numero ng hotline'),
      matching: find.byType(InkWell),
    );
    expect(tester.getSize(row.first).height, greaterThanOrEqualTo(44));
  });
}
