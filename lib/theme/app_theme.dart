import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Centralized color palette for the SERBIS app.
/// Mirrors the redesigned mockup: deep forest green primary,
/// warm paper background, and clear status accent colors.
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

/// Text style helpers. Headings use Lexend (built for reading clarity),
/// body copy uses Inter.
class AppText {
  AppText._();

  static TextStyle display({
    double size = 20,
    FontWeight weight = FontWeight.w700,
    Color color = AppColors.ink,
    double? letterSpacing,
    double? height,
  }) =>
      GoogleFonts.lexend(
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
      GoogleFonts.inter(
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
    textTheme: GoogleFonts.interTextTheme(base.textTheme),
    colorScheme: base.colorScheme.copyWith(
      primary: AppColors.green700,
      secondary: AppColors.amber600,
      surface: AppColors.surface,
    ),
    splashFactory: InkRipple.splashFactory,
    dividerColor: AppColors.line,
  );
}
