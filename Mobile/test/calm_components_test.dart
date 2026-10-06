import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/feedback.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/loading.dart';
import 'package:serbis/widgets/offline_banner.dart';
import 'package:serbis/widgets/shared_widgets.dart';
import 'package:serbis/widgets/status_line.dart';

// The shared pieces of the calm redesign (phase 1): theme tokens, status line,
// buttons, dialog, toast, feedback boxes and loading states.

Widget _host(Widget child, {bool reduceMotion = false}) => MaterialApp(
      theme: buildAppTheme(),
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: reduceMotion),
        child: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: child)),
      ),
    );

Color? _boxColor(WidgetTester tester, Finder f) =>
    (tester.widget<Container>(f).decoration as BoxDecoration?)?.color;

void main() {
  group('theme', () {
    test('tokens match the style rules', () {
      expect(AppColors.header, const Color(0xFF16483A));
      expect(AppColors.line, const Color(0xFFECE7DE));
      expect(AppColors.divider, const Color(0xFFF1EDE5));
      expect(AppColors.inkFaint, const Color(0xFF5F6862));
      expect(AppColors.red600, const Color(0xFFA8352A));
      expect(AppColors.green700, const Color(0xFF1F6F4A));
      expect(AppRadius.header, 20);
    });

    test('type roles are 20/18/16/14 and never heavier than 600', () {
      expect(AppText.pageTitle().fontSize, 20);
      expect(AppText.cardTitle().fontSize, 18);
      expect(AppText.section().fontSize, 16);
      expect(AppText.detail().fontSize, 14);
      for (final s in [AppText.pageTitle(), AppText.cardTitle(), AppText.section(), AppText.display(), AppText.fieldLabel()]) {
        expect(s.fontWeight, FontWeight.w600);
      }
      expect(AppText.detail().fontFeatures, contains(const FontFeature.tabularFigures()));
    });

    testWidgets('the tab header is flat dark green with 20 corners', (tester) async {
      await tester.pumpWidget(_host(const TabHeaderBar(title: 'Track', subtitle: 'Your requests', filipino: false)));
      final box = tester.widget<Container>(find.descendant(of: find.byType(TabHeaderBar), matching: find.byType(Container)).first);
      final deco = box.decoration as BoxDecoration;
      expect(deco.gradient, isNull);
      expect(deco.color, AppColors.header);
      expect(deco.borderRadius, const BorderRadius.vertical(bottom: Radius.circular(20)));
      expect(tester.widget<Text>(find.text('Track')).style!.fontSize, 20);
    });
  });

  group('StatusLine', () {
    testWidgets('draws a dot in the tone colour beside the words', (tester) async {
      await tester.pumpWidget(_host(const StatusLine(label: 'Under review', tone: StatusTone.amber)));
      expect(find.text('Under review'), findsOneWidget);
      final dot = find.byWidgetPredicate((w) =>
          w is Container && (w.decoration as BoxDecoration?)?.shape == BoxShape.circle);
      expect(_boxColor(tester, dot), AppColors.amberDot);
      expect(tester.getSize(dot), const Size(8, 8));
    });

    testWidgets('large variant is 20px with a 12px dot and an Updated line', (tester) async {
      final now = DateTime.now();
      await tester.pumpWidget(_host(StatusLine(
        label: 'Completed',
        tone: StatusTone.green,
        large: true,
        updatedAt: DateTime(now.year, now.month, now.day, 9, 14),
      )));
      expect(tester.widget<Text>(find.text('Completed')).style!.fontSize, 20);
      expect(find.text('Updated today, 9:14 AM'), findsOneWidget);
      final dot = find.byWidgetPredicate((w) =>
          w is Container && (w.decoration as BoxDecoration?)?.shape == BoxShape.circle);
      expect(tester.getSize(dot), const Size(12, 12));
    });

    test('Updated line reads in Filipino and dates older changes', () {
      final now = DateTime.now();
      expect(StatusLine.updatedText(DateTime(now.year, now.month, now.day, 15, 4), true), 'Na-update ngayon, 3:04 PM');
      expect(StatusLine.updatedText(DateTime(2020, 8, 1, 9, 0), false), 'Updated Aug 1, 9:00 AM');
    });

    test('each tone has its own dot', () {
      final dots = StatusTone.values.map((t) => StatusLine.colorsOf(t).$1).toSet();
      expect(dots, hasLength(StatusTone.values.length));
    });
  });

  group('AppButton', () {
    testWidgets('is 52 tall in every style', (tester) async {
      await tester.pumpWidget(_host(Column(children: [
        for (final s in AppButtonStyle.values) AppButton(label: s.name, style: s, onPressed: () {}),
      ])));
      for (final s in AppButtonStyle.values) {
        expect(tester.getSize(find.widgetWithText(AppButton, s.name)).height, 52, reason: s.name);
      }
    });

    testWidgets('cancel is a red outline, destructive a red fill', (tester) async {
      await tester.pumpWidget(_host(Column(children: [
        AppButton(label: 'Cancel request', style: AppButtonStyle.cancel, onPressed: () {}),
        AppButton(label: 'Discard', style: AppButtonStyle.destructive, onPressed: () {}),
      ])));
      final cancel = tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Cancel request'));
      expect(cancel.style!.side!.resolve({})!.color, AppColors.redBorder);
      expect(cancel.style!.foregroundColor!.resolve({}), AppColors.red600);
      final destroy = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Discard'));
      expect(destroy.style!.backgroundColor!.resolve({}), AppColors.red600);
    });

    testWidgets('while sending it keeps its words, shows a spinner and blocks taps', (tester) async {
      var taps = 0;
      await tester.pumpWidget(_host(AppButton(
        label: 'Submit request',
        loadingLabel: 'Sending request…',
        loading: true,
        onPressed: () => taps++,
      )));
      expect(find.text('Sending request…'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      await tester.tap(find.byType(AppButton));
      expect(taps, 0);
      // Full colour, not the faded disabled look.
      final b = tester.widget<ElevatedButton>(find.byType(ElevatedButton));
      expect(b.style!.backgroundColor!.resolve({WidgetState.disabled}), AppColors.green700);
    });

    testWidgets('without loadingLabel the label stays as it was', (tester) async {
      await tester.pumpWidget(_host(const AppButton(label: 'Verify', loading: true)));
      expect(find.text('Verify'), findsOneWidget);
    });

    testWidgets('a disabled button prints why', (tester) async {
      await tester.pumpWidget(_host(const AppButton(label: 'Save changes', disabledHint: 'Change a detail above to save.')));
      expect(find.text('Change a detail above to save.'), findsOneWidget);
      await tester.pumpWidget(_host(AppButton(label: 'Save changes', disabledHint: 'Change a detail above to save.', onPressed: () {})));
      expect(find.text('Change a detail above to save.'), findsNothing);
    });

    testWidgets('the spinner stands still with reduce motion on', (tester) async {
      await tester.pumpWidget(_host(const AppButton(label: 'Verify', loading: true), reduceMotion: true));
      expect(tester.widget<CircularProgressIndicator>(find.byType(CircularProgressIndicator)).value, isNotNull);
    });
  });

  group('ConfirmDialog', () {
    testWidgets('main action on top in red, safe choice under it', (tester) async {
      await tester.pumpWidget(_host(ConfirmDialog(
        title: 'Cancel this request?',
        body: "You can't undo this.",
        keepLabel: 'Keep request',
        confirmLabel: 'Cancel request',
        onKeep: () {},
        onConfirm: () {},
      )));
      final confirm = find.widgetWithText(ElevatedButton, 'Cancel request');
      final keep = find.widgetWithText(OutlinedButton, 'Keep request');
      expect(tester.getTopLeft(confirm).dy, lessThan(tester.getTopLeft(keep).dy));
      expect(tester.widget<ElevatedButton>(confirm).style!.backgroundColor!.resolve({}), AppColors.red600);
      expect(tester.widget<Text>(find.text('Cancel this request?')).style!.fontSize, 18);
      expect(find.byIcon(Icons.warning_amber_rounded), findsOneWidget);
    });

    testWidgets('a non-destructive dialog confirms in green', (tester) async {
      await tester.pumpWidget(_host(ConfirmDialog(
        title: 'Log out of SERBIS?',
        body: 'You will need your number and password.',
        keepLabel: 'Stay logged in',
        confirmLabel: 'Log out',
        destructive: false,
        icon: Icons.logout_rounded,
        onKeep: () {},
        onConfirm: () {},
      )));
      final confirm = find.widgetWithText(ElevatedButton, 'Log out');
      expect(tester.widget<ElevatedButton>(confirm).style!.backgroundColor!.resolve({}), AppColors.green700);
      expect(find.byIcon(Icons.logout_rounded), findsOneWidget);
    });
  });

  group('toast', () {
    testWidgets('dark, four seconds, with an action', (tester) async {
      var viewed = false;
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showAppSnackBar(context, 'Request sent. Follow it in Track.',
                  actionLabel: 'View', onAction: () => viewed = true),
              child: const Text('go'),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      final bar = tester.widget<SnackBar>(find.byType(SnackBar));
      expect(bar.backgroundColor, AppColors.toast);
      expect(bar.duration, const Duration(seconds: 4));
      expect(bar.behavior, SnackBarBehavior.floating);
      await tester.tap(find.text('View'));
      expect(viewed, isTrue);
    });

    testWidgets('goes away by itself even with an action', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showAppSnackBar(context, "Couldn't send.", isError: true, actionLabel: 'Try again'),
              child: const Text('go'),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      expect(find.byIcon(Icons.warning_amber_rounded), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
      expect(find.byType(SnackBar), findsNothing);
    });
  });

  group('feedback', () {
    testWidgets('offline banner is dark grey with white words', (tester) async {
      await tester.pumpWidget(_host(const OfflineBanner(filipino: false)));
      final material = tester.widget<Material>(find.descendant(of: find.byType(OfflineBanner), matching: find.byType(Material)).first);
      expect(material.color, AppColors.offline);
      expect(tester.widget<Text>(find.textContaining("Requests can't be sent")).style!.color, Colors.white);
    });

    testWidgets("couldn't-load box retries", (tester) async {
      var retried = 0;
      await tester.pumpWidget(_host(LoadErrorBox(
        title: "Couldn't load your requests",
        body: 'Check your connection, then try again.',
        onRetry: () => retried++,
      )));
      expect(find.byType(StatusLine), findsOneWidget);
      await tester.tap(find.text('Try again'));
      expect(retried, 1);
    });

    testWidgets("couldn't-load box speaks Filipino", (tester) async {
      await tester.pumpWidget(_host(LoadErrorBox(title: 'x', body: 'y', onRetry: () {}, filipino: true)));
      expect(find.text('Subukang muli'), findsOneWidget);
    });

    testWidgets('form summary counts the fields', (tester) async {
      await tester.pumpWidget(_host(const Column(children: [
        FormErrorSummary(count: 2),
        FormErrorSummary(count: 1, filipino: true),
      ])));
      expect(find.textContaining('2 fields need attention.'), findsOneWidget);
      expect(find.textContaining('1 field ang kailangang ayusin.'), findsOneWidget);
    });

    testWidgets('a field error shows a sign, the words and a 2px red border', (tester) async {
      await tester.pumpWidget(_host(AppTextField(
        label: 'Mobile number',
        hint: '09XXXXXXXXX',
        controller: TextEditingController(),
        errorText: 'Enter all 11 digits, starting with 09.',
      )));
      expect(find.text('Enter all 11 digits, starting with 09.'), findsOneWidget);
      expect(find.descendant(of: find.byType(FieldError), matching: find.byIcon(Icons.warning_amber_rounded)), findsOneWidget);
      final border = tester.widget<TextField>(find.byType(TextField)).decoration!.errorBorder as OutlineInputBorder;
      expect(border.borderSide.width, 2);
      expect(border.borderSide.color, AppColors.red600);
    });

    testWidgets('empty state offers its one action', (tester) async {
      var browsed = false;
      await tester.pumpWidget(_host(EmptyState(
        title: 'No requests yet',
        body: 'When you ask MDRRMO for help, you can follow it here.',
        actionLabel: 'Browse services',
        onAction: () => browsed = true,
      )));
      await tester.tap(find.text('Browse services'));
      expect(browsed, isTrue);
      expect(tester.getSize(find.widgetWithText(ElevatedButton, 'Browse services')).height, greaterThanOrEqualTo(52));
    });
  });

  group('loading', () {
    testWidgets('skeleton rows draw the asked number of rows and say Loading', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_host(const SkeletonRows(count: 4)));
      expect(find.bySemanticsLabel('Loading'), findsOneWidget);
      expect(find.byType(ShaderMask), findsOneWidget);
      // Two blocks a row.
      final blocks = find.byWidgetPredicate((w) => w is FractionallySizedBox);
      expect(blocks, findsNWidgets(8));
      handle.dispose();
    });

    testWidgets('no sweep with reduce motion on, so the tree settles', (tester) async {
      await tester.pumpWidget(_host(const Column(children: [SkeletonRows(), SkeletonCard()]), reduceMotion: true));
      expect(find.byType(ShaderMask), findsNothing);
      await tester.pumpAndSettle();
    });

    testWidgets('skeleton card speaks Filipino to screen readers', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_host(const SkeletonCard(filipino: true), reduceMotion: true));
      expect(find.bySemanticsLabel('Naglo-load'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('upload row shows sizes, a bar, and cancels', (tester) async {
      var cancelled = false;
      await tester.pumpWidget(_host(UploadProgressRow(
        name: 'valid ID',
        sentBytes: (1.2 * 1024 * 1024).round(),
        totalBytes: (1.8 * 1024 * 1024).round(),
        onCancel: () => cancelled = true,
      )));
      expect(find.text('Uploading valid ID'), findsOneWidget);
      expect(find.text('1.2 MB of 1.8 MB'), findsOneWidget);
      expect(tester.widget<LinearProgressIndicator>(find.byType(LinearProgressIndicator)).value, closeTo(2 / 3, .01));
      await tester.tap(find.text('Cancel'));
      expect(cancelled, isTrue);
    });

    test('sizes under a megabyte read in KB', () {
      expect(UploadProgressRow.formatBytes(350 * 1024), '350 KB');
    });

    testWidgets('refresh pill says Updating…', (tester) async {
      await tester.pumpWidget(_host(const RefreshPill(filipino: true)));
      expect(find.text('Ina-update…'), findsOneWidget);
    });
  });
}
