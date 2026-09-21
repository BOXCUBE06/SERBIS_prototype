import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/theme/app_theme.dart';

/// WCAG 2.x contrast ratio between two opaque colours.
double _ratio(Color a, Color b) {
  double lum(Color c) {
    double channel(double v) => v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
    return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
  }

  final hi = math.max(lum(a), lum(b));
  final lo = math.min(lum(a), lum(b));
  return (hi + 0.05) / (lo + 0.05);
}

void main() {
  // Every surface a line of text is drawn on.
  const grounds = {
    'surface': AppColors.surface,
    'paper': AppColors.paper,
    'grey50': AppColors.grey50,
    'green50': AppColors.green50,
    'amber50': AppColors.amber50,
    'red50': AppColors.red50,
    'blue50': AppColors.blue50,
  };

  group('body and secondary text reach 4.5:1 on every surface it sits on', () {
    for (final entry in {
      'ink': AppColors.ink,
      'inkMuted': AppColors.inkMuted,
      'inkFaint': AppColors.inkFaint,
    }.entries) {
      for (final ground in grounds.entries) {
        test('${entry.key} on ${ground.key}', () {
          expect(_ratio(entry.value, ground.value), greaterThanOrEqualTo(4.5));
        });
      }
    }
  });

  group('accent text on its own tint', () {
    final pairs = {
      'amber600 on amber50': (AppColors.amber600, AppColors.amber50),
      'amber600 on surface': (AppColors.amber600, AppColors.surface),
      'red600 on red50': (AppColors.red600, AppColors.red50),
      'red600 on surface': (AppColors.red600, AppColors.surface),
      'blue600 on blue50': (AppColors.blue600, AppColors.blue50),
      'green700 on green50': (AppColors.green700, AppColors.green50),
      'green700 on surface': (AppColors.green700, AppColors.surface),
      'green700 on paper': (AppColors.green700, AppColors.paper),
      'green900 on green50': (AppColors.green900, AppColors.green50),
      'green900 on paper': (AppColors.green900, AppColors.paper),
    };
    for (final entry in pairs.entries) {
      test(entry.key, () {
        expect(_ratio(entry.value.$1, entry.value.$2), greaterThanOrEqualTo(4.5));
      });
    }
  });

  group('status badges', () {
    for (final status in ReqStatus.values) {
      test('request status ${status.name}', () {
        expect(_ratio(status.fg, status.bg), greaterThanOrEqualTo(4.5));
      });
    }
    for (final status in BorrowStatus.values) {
      test('borrow status ${status.name}', () {
        expect(_ratio(status.fg, status.bg), greaterThanOrEqualTo(4.5));
      });
    }
  });

  group('service icons reach 3:1, the bar for a graphic that carries meaning', () {
    for (final code in [
      'ambulance-medical-response',
      'road-clearing',
      'relief-goods-distribution',
      'animal-rescue',
      'power-line-repair',
      'sandbagging',
      'drrm-trainings-and-seminars',
      'simulation-drills-nsed',
      'mdrrmo-certification',
      'others',
      'anything-new',
    ]) {
      test(code, () {
        final badge = badgeForServiceCode(code);
        expect(_ratio(badge.fg, badge.bg), greaterThanOrEqualTo(3.0));
      });
    }
  });

  group('form labels', () {
    test('are large, bold and full-strength ink', () {
      final style = AppText.fieldLabel();
      expect(style.fontSize, greaterThanOrEqualTo(14));
      expect(style.fontWeight, FontWeight.w700);
      expect(style.color, AppColors.ink);
      expect(_ratio(style.color!, AppColors.surface), greaterThanOrEqualTo(4.5));
      expect(_ratio(style.color!, AppColors.paper), greaterThanOrEqualTo(4.5));
    });

    test("Material's own labelled fields get the same treatment from the theme", () {
      final decoration = buildAppTheme().inputDecorationTheme;
      expect(decoration.labelStyle!.fontSize, greaterThanOrEqualTo(14));
      expect(decoration.labelStyle!.fontWeight!.value, greaterThanOrEqualTo(600));
      expect(_ratio(decoration.labelStyle!.color!, AppColors.paper), greaterThanOrEqualTo(4.5));
      expect(_ratio(decoration.hintStyle!.color!, AppColors.surface), greaterThanOrEqualTo(4.5));
    });
  });
}
