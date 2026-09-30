import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/service_widgets.dart';

Future<void> _pump(WidgetTester tester, {VoidCallback? onViewHotlines, bool filipino = false}) async {
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: SafetyNotice(filipino: filipino, onViewHotlines: onViewHotlines),
    ),
  ));
}

void main() {
  testWidgets('shows the warning and no numbers: the hotlines live in the Library', (tester) async {
    await _pump(tester, onViewHotlines: () {});

    expect(find.text('Non-life-threatening use only'), findsOneWidget);
    expect(find.textContaining('life-threatening emergency'), findsOneWidget);
    expect(find.text(kHotlines.first.numbers.first.number), findsNothing);
    expect(find.textContaining(kHotlines.first.numbers.first.number), findsNothing);
  });

  testWidgets('the link opens the hotlines and is a 44dp target', (tester) async {
    var opened = 0;
    await _pump(tester, onViewHotlines: () => opened++);

    final link = find.ancestor(of: find.text('View emergency hotlines'), matching: find.byType(InkWell));
    expect(tester.getSize(link.first).height, greaterThanOrEqualTo(44));

    await tester.tap(find.text('View emergency hotlines'));
    expect(opened, 1);
  });

  testWidgets('no callback draws no link; the link is translated', (tester) async {
    await _pump(tester);
    expect(find.text('View emergency hotlines'), findsNothing);

    await _pump(tester, onViewHotlines: () {}, filipino: true);
    expect(find.text('Tingnan ang mga emergency hotline'), findsOneWidget);
  });
}
