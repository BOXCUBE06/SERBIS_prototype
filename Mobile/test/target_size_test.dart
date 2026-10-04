// The 48dp buttons and 44dp links hold on a desktop browser too. Material's
// default density is adaptive: compact on desktop and web platforms, which took
// 8dp off every button's minimum height (a 48dp AppButton measured 40dp when the
// app ran in Chrome on a laptop). The app theme pins it to standard.

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/auth_layout.dart';
import 'package:serbis/widgets/shared_widgets.dart';

void main() {
  for (final platform in [TargetPlatform.windows, TargetPlatform.macOS, TargetPlatform.linux, TargetPlatform.android]) {
    testWidgets('buttons and links meet their size on $platform', (tester) async {
      debugDefaultTargetPlatformOverride = platform;
      // Reset in the body: the framework checks the variable before tearDowns run.
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: Column(
            children: [
              AppButton(label: 'Primary', onPressed: () {}),
              AppButton(label: 'Outline', style: AppButtonStyle.outline, onPressed: () {}),
              AppButton(label: 'Danger', style: AppButtonStyle.ghostRed, onPressed: () {}),
              AuthLink(label: 'Link', onPressed: () {}),
              SectionHeader(title: 'Heading', actionLabel: 'See all', onAction: () {}),
            ],
          ),
        ),
      ));

      for (final label in ['Primary', 'Outline', 'Danger']) {
        expect(
          tester.getSize(find.widgetWithText(AppButton, label)).height,
          greaterThanOrEqualTo(48),
          reason: label,
        );
      }
      for (final label in ['Link', 'See all']) {
        final button = find.ancestor(of: find.text(label), matching: find.byType(TextButton));
        expect(tester.getSize(button).height, greaterThanOrEqualTo(44), reason: label);
      }
      debugDefaultTargetPlatformOverride = null;
    });
  }
}
