// The Library, the article reader and the hotlines page on the new style:
// shared title header, 600dp column, 48dp number rows, a real 44dp language
// toggle and 16px reading text.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/data/safety_files.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/screens/library/article_reader_screen.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/borrow_request_widgets.dart' show StatusBox;
import 'package:serbis/widgets/hotline_list.dart';
import 'package:serbis/widgets/request_summary.dart' show SummaryCard;
import 'package:serbis/widgets/shared_widgets.dart';

class _Api extends ApiService {}

Future<AppState> _pumpLibrary(
  WidgetTester tester, {
  bool filipino = false,
  Size size = const Size(390, 3000),
  VoidCallback? onBack,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final state = AppState(_Api());
  if (filipino) state.setLanguage(AppLanguage.filipino);
  state.materials.add(InfoMaterial(
    id: 1,
    title: 'Evacuation map 2026',
    fileType: 'pdf',
    sizeBytes: 2048,
    url: 'https://example.test/1.pdf',
    publishedAt: DateTime(2026, 9, 1),
  ));

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: LibraryScreen(
        appState: state,
        onOpenNotifications: () {},
        onOpenProfile: () {},
        onBack: onBack,
      ),
    ),
  ));
  await tester.pumpAndSettle();
  return state;
}

const _fixture = LibraryArticle(
  title: 'Fixture title',
  titleFil: 'Pamagat',
  subtitle: 'Fixture subtitle',
  subtitleFil: 'Subtitle',
  icon: Icons.healing_rounded,
  iconBg: Colors.white,
  iconFg: Colors.black,
  sections: [
    ArticleSection(heading: 'Heading', body: 'Body paragraph.', bullets: ['Bullet a']),
  ],
  sectionsFil: [ArticleSection(heading: 'Pamagat ng bahagi', body: 'Talata.')],
);

Future<void> _pumpArticle(WidgetTester tester, {Size size = const Size(390, 1600)}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: const ArticleReaderScreen(article: _fixture, filipino: false),
  ));
  await tester.pumpAndSettle();
}

void main() {
  group('the Library', () {
    testWidgets('sits under the shared header, with a back arrow when opened as a page', (tester) async {
      var back = 0;
      await _pumpLibrary(tester, onBack: () => back++);

      expect(find.text('Safety library'), findsOneWidget);
      expect(find.text('First aid, preparedness and hotlines'), findsOneWidget);
      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      expect(back, 1);
    });

    testWidgets('headings are sentence case', (tester) async {
      await _pumpLibrary(tester);

      for (final heading in ['Emergency hotlines', 'Basic first aid', 'Disaster preparedness', 'MDRRMO documents']) {
        expect(find.text(heading), findsOneWidget, reason: heading);
      }
      expect(find.text('Emergency Hotlines'), findsNothing);
    });

    testWidgets('every hotline number is a row at least 48dp tall', (tester) async {
      await _pumpLibrary(tester);

      for (final hotline in kHotlines) {
        for (final n in hotline.numbers) {
          final text = n.label == null ? n.number : '${n.label} · ${n.number}';
          final row = find.ancestor(of: find.text(text), matching: find.byType(InkWell)).first;
          expect(tester.getSize(row).height, greaterThanOrEqualTo(48), reason: text);
        }
      }
    });

    testWidgets('marks each built-in section "Saved" once, in its heading, not on every row', (tester) async {
      await _pumpLibrary(tester);

      // Hotlines, first aid and preparedness; the document row has its own
      // Download pill, which is not "Saved".
      expect(find.text('Saved'), findsNWidgets(3));
    });

    testWidgets('keeps its content to 600dp on a wide screen', (tester) async {
      await _pumpLibrary(tester, size: const Size(1400, 3000));

      expect(tester.getSize(find.byType(SummaryCard).first).width, lessThanOrEqualTo(600));
    });

    for (final width in [320.0, 390.0]) {
      testWidgets('fits ${width.toInt()}px in Filipino without overflow', (tester) async {
        await _pumpLibrary(tester, filipino: true, size: Size(width, 3200));

        expect(tester.takeException(), isNull);
        expect(find.text('Aklatan ng kaligtasan'), findsOneWidget);
      });
    }
  });

  group('the article reader', () {
    testWidgets('each language segment is a 44dp target', (tester) async {
      await _pumpArticle(tester);

      for (final label in ['EN', 'FIL']) {
        final segment = find.ancestor(of: find.text(label), matching: find.byType(InkWell)).first;
        final size = tester.getSize(segment);
        expect(size.height, greaterThanOrEqualTo(44), reason: label);
        expect(size.width, greaterThanOrEqualTo(44), reason: label);
      }
    });

    testWidgets('the whole segment switches the language, not only the letters', (tester) async {
      await _pumpArticle(tester);

      final fil = find.ancestor(of: find.text('FIL'), matching: find.byType(InkWell)).first;
      // The segment's left edge, well off the two letters.
      await tester.tapAt(tester.getTopLeft(fil) + const Offset(4, 22));
      await tester.pumpAndSettle();

      expect(find.text('Pamagat'), findsOneWidget);
    });

    testWidgets('reads at 16px and bullets keep their dots', (tester) async {
      await _pumpArticle(tester);

      expect(tester.widget<Text>(find.text('Body paragraph.')).style!.fontSize, greaterThanOrEqualTo(16));
      expect(tester.widget<Text>(find.text('Bullet a')).style!.fontSize, greaterThanOrEqualTo(16));
    });

    testWidgets('says it is available offline in a status box', (tester) async {
      await _pumpArticle(tester);

      expect(find.byType(StatusBox), findsOneWidget);
      expect(find.text('Available offline'), findsOneWidget);
    });

    testWidgets('keeps its text to 600dp on a wide screen', (tester) async {
      await _pumpArticle(tester, size: const Size(1400, 1200));

      expect(tester.getSize(find.byType(StatusBox)).width, lessThanOrEqualTo(600));
    });

    testWidgets('every catalogue article fits 320px in Filipino without overflow', (tester) async {
      for (final entry in libraryArticles.entries) {
        tester.view.physicalSize = const Size(320, 2400);
        tester.view.devicePixelRatio = 1;
        await tester.pumpWidget(MaterialApp(
          theme: buildAppTheme(),
          home: ArticleReaderScreen(article: entry.value, filipino: true),
        ));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: entry.key);
      }
      addTearDown(tester.view.reset);
    });
  });

  group('the hotlines page before login', () {
    testWidgets('has the shared header, a back arrow and 48dp number rows', (tester) async {
      tester.view.physicalSize = const Size(390, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: const HotlinesPage(hotlines: kHotlines),
      ));

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Emergency hotlines'), findsOneWidget);
      expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
      final first = kHotlines.first.numbers.first;
      final text = first.label == null ? first.number : '${first.label} · ${first.number}';
      final row = find.ancestor(of: find.text(text), matching: find.byType(InkWell)).first;
      expect(tester.getSize(row).height, greaterThanOrEqualTo(48));
    });
  });
}
