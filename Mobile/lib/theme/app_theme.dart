import 'package:flutter/material.dart';

class AppColors {
  AppColors._();

  static const green900 = Color(0xFF123A2B);
  static const green700 = Color(0xFF1F6F4A);
  static const green600 = Color(0xFF2C8C5E);
  static const green50 = Color(0xFFE8F3EC);

  static const amber600 = Color(0xFFD9842B);
  static const amber50 = Color(0xFFFCEFDF);

  static const red600 = Color(0xFFC0432D);
  static const red50 = Color(0xFFFBEAE6);

  static const blue600 = Color(0xFF2D6CA8);
  static const blue50 = Color(0xFFE7F0F8);

  static const paper = Color(0xFFF6F3EC);
  static const surface = Color(0xFFFFFFFF);

  static const ink = Color(0xFF1E2A24);
  static const inkMuted = Color(0xFF6E7B73);
  static const inkFaint = Color(0xFF9AA59E);

  static const line = Color(0xFFE7E2D6);
  static const grey50 = Color(0xFFF1EFEA);

  static const headerGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [green900, green700, green600],
  );
}

/// A 4-unit scale so spacing reads as a deliberate rhythm instead of one
/// value (13, formerly) repeated between every field regardless of whether
/// it separates two related inputs or two unrelated sections.
class AppSpacing {
  AppSpacing._();

  /// Between a label and its own input, or two lines of the same thought.
  static const double xs = 4;

  /// Between fields that belong to the same group (e.g. two rows of one
  /// [FormSection]) — tight, so the grouping reads without a drawn border.
  static const double sm = 8;

  /// Default field-to-field gap outside an explicit group.
  static const double md = 12;

  /// Between a [FormSection]'s label and its first field, and general
  /// component padding.
  static const double lg = 16;

  /// Between two [FormSection]s — the gap that has to read as "new topic"
  /// against [sm]'s "same topic".
  static const double xl = 24;

  /// Around a whole screen or sheet.
  static const double xxl = 32;
}

class AppText {
  AppText._();

  /// Headings and anything that carries emphasis.
  ///
  /// The family name has to match `pubspec.yaml` exactly — a typo does not fail
  /// the build, it silently falls back to the platform default, which is the
  /// same failure `google_fonts` produced offline and the reason both families
  /// are asserted in `test/app_fonts_test.dart`.
  static const String displayFamily = 'Lexend';

  /// Body copy, and the family behind the whole Material text theme.
  static const String bodyFamily = 'Inter';

  /// Only these are bundled. A weight outside the set does not fail — Flutter
  /// picks the nearest declared one — so a `w300` would render as `w400` and
  /// look almost right, which is worse than an error.
  static const List<FontWeight> bundledWeights = [
    FontWeight.w400,
    FontWeight.w500,
    FontWeight.w600,
    FontWeight.w700,
  ];

  static TextStyle display({
    double size = 20,
    FontWeight weight = FontWeight.w700,
    Color color = AppColors.ink,
    double? letterSpacing,
    double? height,
  }) =>
      TextStyle(
        fontFamily: displayFamily,
        fontSize: size,
        fontWeight: weight,
        color: color,
        letterSpacing: letterSpacing,
        height: height,
      );

  static TextStyle body({
    double size = 13,
    FontWeight weight = FontWeight.w400,
    Color color = AppColors.ink,
    double? height,
  }) =>
      TextStyle(
        fontFamily: bodyFamily,
        fontSize: size,
        fontWeight: weight,
        color: color,
        height: height,
      );
}

ThemeData buildAppTheme() {
  final base = ThemeData(useMaterial3: true);
  return base.copyWith(
    scaffoldBackgroundColor: AppColors.paper,
    // Replaces GoogleFonts.interTextTheme, which did the same thing over the
    // network. `apply` keeps Material's own sizes and only swaps the family.
    textTheme: base.textTheme.apply(fontFamily: AppText.bodyFamily),
    colorScheme: base.colorScheme.copyWith(
      primary: AppColors.green700,
      secondary: AppColors.amber600,
      surface: AppColors.surface,
    ),
    splashFactory: InkRipple.splashFactory,
    dividerColor: AppColors.line,
  );
}
