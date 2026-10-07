import 'package:flutter/material.dart';

class AppColors {
  AppColors._();

  static const green900 = Color(0xFF123A2B);
  static const green700 = Color(0xFF1F6F4A);
  static const green600 = Color(0xFF2C8C5E);
  static const green50 = Color(0xFFE8F3EC);

  static const amber600 = Color(0xFF9A5A0F);
  static const amber50 = Color(0xFFFCEFDF);

  static const red600 = Color(0xFFA8352A);
  static const red50 = Color(0xFFFBEDEB);

  /// Dot of a red status line.
  static const redDot = Color(0xFFC0392B);

  /// Outline of a red action drawn on white (Cancel, Log out).
  static const redBorder = Color(0xFFE3BDB6);

  /// Outline of an amber note on [amber50].
  static const amberBorder = Color(0xFFF0D2A8);

  static const blue600 = Color(0xFF2D6CA8);
  static const blue50 = Color(0xFFE7F0F8);

  static const paper = Color(0xFFF6F3EC);
  static const surface = Color(0xFFFFFFFF);

  static const ink = Color(0xFF1E2A24);
  static const inkMuted = Color(0xFF4A554F);
  /// Placeholders and the faintest readable text (5:1 on white).
  static const inkFaint = Color(0xFF5F6862);

  /// Card and list borders.
  static const line = Color(0xFFECE7DE);

  /// Thin dividers between rows inside one card.
  static const divider = Color(0xFFF1EDE5);
  static const grey50 = Color(0xFFF1EFEA);

  // Inputs: a warm off-white fill that never reads as disabled.
  static const fieldFill = Color(0xFFFBFAF7);
  static const fieldBorder = Color(0xFFD9D2C6);

  /// Tonal button fill, and selected option card fill.
  static const greenTonal = Color(0xFFE3F0E8);
  static const greenSelected = Color(0xFFEEF6F1);

  /// "In progress" notice row.
  static const greenNotice = Color(0xFFE5F2EA);
  static const greenNoticeBorder = Color(0xFFA9D3B8);

  /// Low-stock text and dot.
  static const amberInk = Color(0xFF8A4B0C);
  static const amberDot = Color(0xFFD98A1E);

  /// Dashed "ask for something else" card and its icon tile.
  // List cards and section headings of the redesigned tabs.
  static const cardBorder = line;
  static const cardDivider = divider;
  static const sectionInk = Color(0xFF1E3D2F);

  static const dashed = Color(0xFFBFB7A9);
  static const sand = Color(0xFFECE6DC);

  /// Grey status dot (cancelled, closed).
  static const greyDot = Color(0xFF9AA19C);

  /// The flat header behind every screen title. No gradient.
  static const header = Color(0xFF16483A);

  /// Toast background.
  static const toast = Color(0xFF1C2B24);

  /// Toast action and success icon, readable on [toast].
  static const toastAction = Color(0xFF9FD6B5);

  /// Toast warning icon, on [toast].
  static const toastWarn = Color(0xFFF2B49F);

  /// Offline banner background.
  static const offline = Color(0xFF3E4842);

  /// Skeleton placeholder blocks.
  static const skeleton = Color(0xFFECE6DC);
}

/// Corner radii. Anything rounder than half its height reads as a pill.
class AppRadius {
  AppRadius._();

  static const double sm = 10;
  static const double md = 12;
  static const double lg = 14;
  /// List cards (Borrow catalogue).
  static const double card = 16;
  static const double xl = 20;

  /// Bottom corners of the flat header.
  static const double header = 20;
  static const double xxl = 28;
  static const double pill = 999;
}

class AppShadow {
  AppShadow._();

  /// The soft lift under [AppCard].
  static final List<BoxShadow> card = [
    BoxShadow(
      color: AppColors.green900.withValues(alpha: .04),
      blurRadius: 16,
      offset: const Offset(0, 6),
    ),
  ];
}

/// Background/foreground pairs for status chips.
class AppStatus {
  AppStatus._();

  static const info = (bg: AppColors.blue50, fg: AppColors.blue600);
  static const pending = (bg: AppColors.amber50, fg: AppColors.amber600);
  static const success = (bg: AppColors.green50, fg: AppColors.green700);
  static const danger = (bg: AppColors.red50, fg: AppColors.red600);
  static const neutral = (bg: AppColors.grey50, fg: AppColors.inkFaint);

  static const booked = (bg: Color(0xFFEDE7F6), fg: Color(0xFF6A1B9A));
}

/// Screen-level layout distances, by role.
class AppLayout {
  AppLayout._();

  /// Left/right screen margin.
  static const double gutter = 22;

  /// Bottom space so content scrolls clear of the bottom nav.
  static const double navClearance = 110;

  /// Snackbar lift above the bottom nav.
  static const double snackBarClearance = 90;

  /// Top padding under the status bar: [AppHeader].
  static const double headerTop = 40;

  /// Top padding: article reader header.
  static const double readerHeaderTop = 48;

  /// Top padding: full-page sub-screen header (offline materials).
  static const double subpageHeaderTop = 56;
}

/// The seven type sizes. Pass to [AppText.display] / [AppText.body].
class AppTextSize {
  AppTextSize._();

  static const double caption = 13;
  static const double small = 14;
  static const double body = 15;
  static const double bodyLg = 16;
  static const double title = 17;
  static const double headline = 21;
  static const double display = 28;

  /// The resident's name on the profile card.
  static const double cardName = 20;

  // Roles of the calm redesign. Weights never exceed 600.
  static const double pageTitle = 20;
  static const double cardTitle = 18;
  static const double section = 16;
  static const double detail = 14;
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

  /// The one bundled family, for headings and body alike.
  ///
  /// The family name has to match `pubspec.yaml` exactly — a typo does not fail
  /// the build, it silently falls back to the platform default, which is the
  /// same failure `google_fonts` produced offline and the reason it is asserted
  /// in `test/app_fonts_test.dart`.
  static const String family = 'PlusJakartaSans';

  /// Headings and anything that carries emphasis.
  static const String displayFamily = family;

  /// Body copy, and the family behind the whole Material text theme.
  static const String bodyFamily = family;

  /// Only these are bundled. A weight outside the set does not fail — Flutter
  /// picks the nearest declared one — so a `w300` would render as `w400` and
  /// look almost right, which is worse than an error. Nothing asks for more
  /// than 600; the 700 file stays in `pubspec.yaml` only so a stray `w700`
  /// still renders in this face rather than a synthetic bold.
  static const List<FontWeight> bundledWeights = [
    FontWeight.w400,
    FontWeight.w500,
    FontWeight.w600,
  ];

  static TextStyle display({
    double size = AppTextSize.headline,
    FontWeight weight = FontWeight.w600,
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

  /// The label above a form field. Large, bold and full-strength ink: a field
  /// that cannot be read at a glance is one a resident fills in wrong, and these
  /// screens are used outdoors, on small phones, by people of every age.
  static TextStyle fieldLabel() =>
      display(size: AppTextSize.body, weight: FontWeight.w600, height: 1.3);

  /// 20/600: the title in the header.
  static TextStyle pageTitle({Color color = AppColors.ink}) =>
      display(size: AppTextSize.pageTitle, color: color, height: 1.25);

  /// 18/600: a card's or dialog's title.
  static TextStyle cardTitle({Color color = AppColors.ink}) =>
      display(size: AppTextSize.cardTitle, color: color, height: 1.3);

  /// 16/600: a section heading above a card or list.
  static TextStyle section({Color color = AppColors.sectionInk}) =>
      display(size: AppTextSize.section, color: color, height: 1.3);

  /// 14: dates, times, reference numbers, with even-width digits so times
  /// line up.
  static TextStyle detail({Color color = AppColors.inkMuted, FontWeight weight = FontWeight.w400}) =>
      body(size: AppTextSize.detail, color: color, weight: weight, height: 1.4)
          .copyWith(fontFeatures: const [FontFeature.tabularFigures()]);

  static TextStyle body({
    double size = AppTextSize.body,
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
    // Material's default is adaptive: compact on desktop and web, which takes 8dp
    // off every button's minimum height. The 48dp and 44dp targets this app
    // promises have to hold in a browser as well.
    visualDensity: VisualDensity.standard,
    scaffoldBackgroundColor: AppColors.paper,
    // Replaces GoogleFonts.interTextTheme, which did the same thing over the
    // network (and Inter itself, replaced by Plus Jakarta Sans). `apply` keeps Material's own sizes and only swaps the family.
    textTheme: base.textTheme.apply(fontFamily: AppText.bodyFamily),
    colorScheme: base.colorScheme.copyWith(
      primary: AppColors.green700,
      secondary: AppColors.amber600,
      surface: AppColors.surface,
    ),
    splashFactory: InkRipple.splashFactory,
    dividerColor: AppColors.divider,
    // Fields built straight on Material's TextFormField (the ones with a
    // labelText) get the same large, bold label and readable hint as the app's
    // own field widgets.
    // A disabled button that carries information ("Resend code in 56s") must
    // stay legible; Material's 38% grey was about 2:1.
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(disabledForegroundColor: AppColors.inkMuted),
    ),
    inputDecorationTheme: InputDecorationTheme(
      labelStyle: AppText.body(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.ink),
      floatingLabelStyle: AppText.body(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.green900),
      hintStyle: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkFaint),
    ),
  );
}
