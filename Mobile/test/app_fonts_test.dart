// M29: the app fetched Lexend and Inter from fonts.gstatic.com at runtime, so a
// first launch with no signal rendered in the platform default — every size and
// metric the layout was tuned against, changed, on the launch this app exists
// for. The fonts are bundled now.
//
// The failure mode these guard against is silent by construction: a missing
// asset, a renamed file or a misspelled family does not fail the build, it just
// falls back — which looks like a slightly-off design rather than a bug.
//
// Note what is *not* asserted here: flutter_test does not load bundled fonts, so
// nothing below proves a glyph reaches a screen. That needs the running app.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/theme/app_theme.dart';

/// Every `asset:` line under `flutter: fonts:`, paired with the family it sits
/// under. Hand-parsed rather than pulling in `package:yaml` for one file.
Map<String, List<String>> _declaredFonts(String pubspec) {
  final result = <String, List<String>>{};
  String? family;

  for (final raw in pubspec.split('\n')) {
    final line = raw.trimRight();
    final familyMatch = RegExp(r'^\s*-\s*family:\s*(\S+)').firstMatch(line);
    if (familyMatch != null) {
      family = familyMatch.group(1);
      result[family!] = <String>[];
      continue;
    }

    final assetMatch = RegExp(r'^\s*-\s*asset:\s*(\S+)').firstMatch(line);
    if (assetMatch != null && family != null) {
      result[family]!.add(assetMatch.group(1)!);
    }
  }

  return result;
}

void main() {
  final pubspec = File('pubspec.yaml').readAsStringSync();
  final declared = _declaredFonts(pubspec);

  group('the fonts are bundled, not fetched', () {
    test('google_fonts is no longer a dependency', () {
      // The point of the task. Leaving the dependency in place would let a
      // single GoogleFonts.lexend() call reintroduce the startup request
      // without anything else changing.
      //
      // Matched as a declaration, not as a substring: the pubspec explains in a
      // comment why the package was dropped, and a bare `contains` fails on the
      // explanation.
      final declaration = RegExp(r'^\s+google_fonts\s*:', multiLine: true);
      expect(declaration.hasMatch(pubspec), isFalse);
    });

    test('both families are declared', () {
      expect(declared.keys, containsAll(<String>['Lexend', 'Inter']));
    });

    test('every declared asset exists on disk', () {
      for (final entry in declared.entries) {
        for (final asset in entry.value) {
          expect(
            File(asset).existsSync(),
            isTrue,
            reason: '${entry.key} declares $asset, which is not in the repo — '
                'the app would silently fall back to the platform font',
          );
        }
      }
    });

    test('each family carries every weight the UI asks for', () {
      // A weight that is declared but absent does not throw: Flutter picks the
      // nearest one, so a missing w600 renders as w400 and reads as a styling
      // slip rather than a missing asset.
      for (final family in <String>['Lexend', 'Inter']) {
        for (final weight in AppText.bundledWeights) {
          expect(
            declared[family],
            contains('assets/fonts/$family-${weight.value}.ttf'),
            reason: '$family is missing weight ${weight.value}',
          );
        }
      }
    });
  });

  group('the styles name the bundled families', () {
    test('display uses Lexend and body uses Inter', () {
      expect(AppText.display().fontFamily, AppText.displayFamily);
      expect(AppText.body().fontFamily, AppText.bodyFamily);
      expect(AppText.displayFamily, 'Lexend');
      expect(AppText.bodyFamily, 'Inter');
    });

    test('the family name matches the pubspec exactly', () {
      // Case included: 'lexend' would fall back silently.
      expect(declared.keys, contains(AppText.displayFamily));
      expect(declared.keys, contains(AppText.bodyFamily));
    });

    test('the theme applies the body family across the Material text theme', () {
      final theme = buildAppTheme();
      expect(theme.textTheme.bodyMedium?.fontFamily, AppText.bodyFamily);
      expect(theme.textTheme.titleLarge?.fontFamily, AppText.bodyFamily);
      expect(theme.textTheme.labelSmall?.fontFamily, AppText.bodyFamily);
    });

    test('only bundled weights are used, so nothing is rendered by fallback',
        () {
      for (final weight in AppText.bundledWeights) {
        expect(AppText.display(weight: weight).fontWeight, weight);
        expect(AppText.body(weight: weight).fontWeight, weight);
      }
    });
  });
}
