// M18: `article_reader_screen.dart` was 0/93 — the worst file in the project
// and the only screen with no test of any kind. It is also the screen a
// resident reads when they have already lost the network: the Library is the
// offline half of the app, so a fault here shows up exactly when nothing can
// be fixed.
//
// The behaviour worth pinning is the language toggle, and it is worth pinning
// because of where the state lives. The screen takes `filipino` as a
// constructor argument but then keeps its own copy, so the toggle re-renders
// the article WITHOUT touching the app-wide language. That is deliberate — a
// resident reading a Tagalog first-aid article in an English app should not
// have the whole UI switch under them — but it is invisible from the outside,
// and the obvious "fix" of reading `widget.filipino` on every build would
// break the toggle while leaving the screen looking correct on first open.
//
// Everything below drives the real `libraryArticles` data rather than fixtures
// where it can: the article bodies are the product, and a test that invents
// its own sections proves the widget renders something, not that it renders
// the material.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/safety_files.dart';
import 'package:serbis/screens/library/article_reader_screen.dart';
import 'package:serbis/theme/app_theme.dart';

/// A phone-shaped viewport. The default 800x600 test surface clips a tall
/// screen so offscreen rows never build, which makes a value under test
/// unfindable for the wrong reason.
Future<void> _pump(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(theme: buildAppTheme(), home: child));
  await tester.pumpAndSettle();
}

Future<void> _open(
  WidgetTester tester,
  LibraryArticle article, {
  bool filipino = false,
}) =>
    _pump(tester, ArticleReaderScreen(article: article, filipino: filipino));

/// A small article whose strings cannot collide with the real catalogue, for
/// the cases that are about the widget's structure rather than the content.
const _fixture = LibraryArticle(
  title: 'Fixture Title EN',
  titleFil: 'Fixture Title FIL',
  subtitle: 'Fixture Subtitle EN',
  subtitleFil: 'Fixture Subtitle FIL',
  icon: Icons.healing_rounded,
  iconBg: Colors.white,
  iconFg: Colors.black,
  sections: [
    ArticleSection(heading: 'EN Heading One', body: 'EN body paragraph.'),
    ArticleSection(heading: 'EN Heading Two', bullets: ['EN bullet a', 'EN bullet b']),
  ],
  sectionsFil: [
    ArticleSection(heading: 'FIL Heading One', body: 'FIL body paragraph.'),
    ArticleSection(heading: 'FIL Heading Two', bullets: ['FIL bullet a']),
  ],
);

LibraryArticle get _cpr => libraryArticles['cpr']!;

void main() {
  group('opening an article', () {
    testWidgets('renders the English title, subtitle and every section',
        (tester) async {
      await _open(tester, _fixture);

      expect(find.text('Fixture Title EN'), findsOneWidget);
      expect(find.text('Fixture Subtitle EN'), findsOneWidget);
      expect(find.text('EN Heading One'), findsOneWidget);
      expect(find.text('EN body paragraph.'), findsOneWidget);
      expect(find.text('EN Heading Two'), findsOneWidget);
      expect(find.text('EN bullet a'), findsOneWidget);
      expect(find.text('EN bullet b'), findsOneWidget);

      // Nothing from the other language leaks in.
      expect(find.text('Fixture Title FIL'), findsNothing);
      expect(find.text('FIL Heading One'), findsNothing);
    });

    testWidgets('opening with filipino: true starts in Filipino',
        (tester) async {
      await _open(tester, _fixture, filipino: true);

      expect(find.text('Fixture Title FIL'), findsOneWidget);
      expect(find.text('FIL Heading One'), findsOneWidget);
      expect(find.text('Fixture Title EN'), findsNothing);
    });

    testWidgets('renders a real catalogue article, not just a fixture',
        (tester) async {
      await _open(tester, _cpr);

      expect(find.text(_cpr.title), findsOneWidget);
      expect(find.text(_cpr.sections.first.heading), findsOneWidget);
    });

    testWidgets('says the material is readable offline', (tester) async {
      // The Library exists to be read with no connection. The notice is the
      // only thing telling a resident that, and it is the reason they stop
      // trying to reload.
      await _open(tester, _fixture);

      expect(find.byIcon(Icons.wifi_off_rounded), findsOneWidget);
      expect(
        find.textContaining('saved for offline reading'),
        findsOneWidget,
      );
    });
  });

  group('the language toggle', () {
    testWidgets('tapping FIL re-renders the article in Filipino',
        (tester) async {
      await _open(tester, _fixture);

      expect(find.text('EN Heading One'), findsOneWidget);

      await tester.tap(find.text('FIL'));
      await tester.pumpAndSettle();

      expect(find.text('Fixture Title FIL'), findsOneWidget);
      expect(find.text('FIL Heading One'), findsOneWidget);
      expect(find.text('FIL bullet a'), findsOneWidget);
      expect(find.text('EN Heading One'), findsNothing);
    });

    testWidgets('tapping EN goes back to English', (tester) async {
      await _open(tester, _fixture, filipino: true);

      await tester.tap(find.text('EN'));
      await tester.pumpAndSettle();

      expect(find.text('Fixture Title EN'), findsOneWidget);
      expect(find.text('EN Heading One'), findsOneWidget);
      expect(find.text('FIL Heading One'), findsNothing);
    });

    testWidgets('the toggle is local to this screen and does not rebuild from the argument',
        (tester) async {
      // The distinction that makes this screen work: `_filipino` is seeded
      // from the constructor once and owned by the State thereafter. If a
      // later refactor reads `widget.filipino` during build instead, the
      // screen still opens in the right language and only this test notices
      // that the toggle stopped doing anything.
      await _open(tester, _fixture);

      await tester.tap(find.text('FIL'));
      await tester.pumpAndSettle();
      expect(find.text('Fixture Title FIL'), findsOneWidget);

      // Rebuild the same State with the original `filipino: false` argument.
      // The resident's choice must survive it.
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: const ArticleReaderScreen(article: _fixture, filipino: false),
      ));
      await tester.pumpAndSettle();

      expect(find.text('Fixture Title FIL'), findsOneWidget,
          reason: 'the toggle must own the language once the screen is open');
      expect(find.text('Fixture Title EN'), findsNothing);
    });

    testWidgets('switching language swaps the sections rather than appending them',
        (tester) async {
      // A `Column` built from the wrong list, or from both, reads as a working
      // screen with the article repeated further down where nobody scrolls.
      await _open(tester, _fixture);

      await tester.tap(find.text('FIL'));
      await tester.pumpAndSettle();

      expect(find.text('FIL Heading One'), findsOneWidget);
      expect(find.text('FIL Heading Two'), findsOneWidget);
      expect(find.text('EN Heading One'), findsNothing);
      expect(find.text('EN Heading Two'), findsNothing);
    });

    testWidgets('both toggle segments are always present and tappable',
        (tester) async {
      await _open(tester, _fixture);

      expect(find.text('EN'), findsOneWidget);
      expect(find.text('FIL'), findsOneWidget);

      // Tapping the already-selected segment is a no-op, not a crash or a
      // flip — a resident double-tapping EN must not land in Filipino.
      await tester.tap(find.text('EN'));
      await tester.pumpAndSettle();

      expect(find.text('Fixture Title EN'), findsOneWidget);
    });
  });

  group('section rendering', () {
    testWidgets('a body-only section renders no bullet dots', (tester) async {
      const bodyOnly = LibraryArticle(
        title: 'T', titleFil: 'T', subtitle: 'S', subtitleFil: 'S',
        icon: Icons.info, iconBg: Colors.white, iconFg: Colors.black,
        sections: [ArticleSection(heading: 'Only body', body: 'Just a paragraph.')],
        sectionsFil: [ArticleSection(heading: 'Only body', body: 'Just a paragraph.')],
      );

      await _open(tester, bodyOnly);

      expect(find.text('Just a paragraph.'), findsOneWidget);
      expect(_bulletDots(tester), 0);
    });

    testWidgets('a bullets-only section renders one dot per bullet',
        (tester) async {
      const bulletsOnly = LibraryArticle(
        title: 'T', titleFil: 'T', subtitle: 'S', subtitleFil: 'S',
        icon: Icons.info, iconBg: Colors.white, iconFg: Colors.black,
        sections: [
          ArticleSection(heading: 'Steps', bullets: ['One', 'Two', 'Three']),
        ],
        sectionsFil: [ArticleSection(heading: 'Steps', bullets: ['Isa'])],
      );

      await _open(tester, bulletsOnly);

      expect(find.text('One'), findsOneWidget);
      expect(find.text('Three'), findsOneWidget);
      expect(_bulletDots(tester), 3,
          reason: 'one dot per bullet, so a dropped bullet is visible');
    });

    testWidgets('a section with both a body and bullets renders both',
        (tester) async {
      const both = LibraryArticle(
        title: 'T', titleFil: 'T', subtitle: 'S', subtitleFil: 'S',
        icon: Icons.info, iconBg: Colors.white, iconFg: Colors.black,
        sections: [
          ArticleSection(
            heading: 'Mixed',
            body: 'Lead paragraph.',
            bullets: ['Point one', 'Point two'],
          ),
        ],
        sectionsFil: [ArticleSection(heading: 'Mixed', body: 'x')],
      );

      await _open(tester, both);

      expect(find.text('Lead paragraph.'), findsOneWidget);
      expect(find.text('Point one'), findsOneWidget);
      expect(_bulletDots(tester), 2);
    });

    testWidgets('an article with no sections still renders its header',
        (tester) async {
      const empty = LibraryArticle(
        title: 'Empty Article', titleFil: 'Walang Laman',
        subtitle: 'Nothing here', subtitleFil: 'Wala',
        icon: Icons.info, iconBg: Colors.white, iconFg: Colors.black,
        sections: [], sectionsFil: [],
      );

      await _open(tester, empty);

      // Degrades to a header and the offline notice rather than throwing.
      expect(find.text('Empty Article'), findsOneWidget);
      expect(find.byIcon(Icons.wifi_off_rounded), findsOneWidget);
    });

    testWidgets('every real catalogue article renders without overflowing',
        (tester) async {
      // The catalogue is hand-written Tagalog and English prose of very
      // different lengths, and a fixed-height container meeting longer text is
      // a repeat offender in this codebase. Driving all of them in both
      // languages is cheap and is the only thing that would catch it.
      for (final entry in libraryArticles.entries) {
        for (final filipino in [false, true]) {
          await _open(tester, entry.value, filipino: filipino);

          expect(tester.takeException(), isNull,
              reason: '${entry.key} overflowed or threw in '
                  '${filipino ? 'Filipino' : 'English'}');
        }
      }
    });
  });

  group('navigation', () {
    testWidgets('the back button pops the screen', (tester) async {
      await _pump(
        tester,
        Navigator(
          onGenerateRoute: (_) => MaterialPageRoute<void>(
            builder: (context) => Scaffold(
              body: Center(
                child: ElevatedButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const ArticleReaderScreen(
                        article: _fixture,
                        filipino: false,
                      ),
                    ),
                  ),
                  child: const Text('open'),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      expect(find.text('Fixture Title EN'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      await tester.pumpAndSettle();

      expect(find.text('Fixture Title EN'), findsNothing);
      expect(find.text('open'), findsOneWidget);
    });
  });
}

/// Counts the 6x6 circular bullet markers `_SectionBlock` draws. Keyed on the
/// shape rather than on a colour so a theme change does not rewrite the test.
int _bulletDots(WidgetTester tester) {
  return tester
      .widgetList<Container>(find.byType(Container))
      .where((c) {
        final d = c.decoration;
        if (d is! BoxDecoration || d.shape != BoxShape.circle) return false;
        final constraints = c.constraints;
        return constraints != null &&
            constraints.maxWidth == 6 &&
            constraints.maxHeight == 6;
      })
      .length;
}
