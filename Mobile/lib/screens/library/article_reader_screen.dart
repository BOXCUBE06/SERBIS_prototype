import 'package:flutter/material.dart';
import '../../data/library_articles.dart';
import '../../theme/app_theme.dart';

/// Full-screen reader for a [LibraryArticle]. Opened by tapping any item
/// in the Safety Library. Renders content in English or Filipino depending
/// on [filipino] (driven by the resident's language setting in Profile).
class ArticleReaderScreen extends StatefulWidget {
  final LibraryArticle article;
  final bool filipino;

  const ArticleReaderScreen({super.key, required this.article, required this.filipino});

  @override
  State<ArticleReaderScreen> createState() => _ArticleReaderScreenState();
}

class _ArticleReaderScreenState extends State<ArticleReaderScreen> {
  late bool _filipino = widget.filipino;

  @override
  Widget build(BuildContext context) {
    final article = widget.article;
    final title = article.titleFor(filipino: _filipino);
    final subtitle = article.subtitleFor(filipino: _filipino);
    final sections = article.sectionsFor(filipino: _filipino);

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(8, 48, 22, 24),
            decoration: const BoxDecoration(
              gradient: AppColors.headerGradient,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
            ),
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned(
                  right: -60,
                  top: -90,
                  child: Container(
                    width: 200,
                    height: 200,
                    decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withOpacity(.05)),
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            IconButton(
                              onPressed: () => Navigator.pop(context),
                              icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
                            ),
                            Container(
                              width: 38,
                              height: 38,
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(.14),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              alignment: Alignment.center,
                              child: Icon(article.icon, color: Colors.white, size: 19),
                            ),
                          ],
                        ),
                        Padding(
                          padding: const EdgeInsets.only(right: 4),
                          child: _LanguageToggle(
                            filipino: _filipino,
                            onChanged: (v) => setState(() => _filipino = v),
                          ),
                        ),
                      ],
                    ),
                    Padding(
                      padding: const EdgeInsets.only(left: 22, top: 10),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            subtitle.toUpperCase(),
                            style: AppText.display(
                              size: 10.5,
                              weight: FontWeight.w600,
                              color: Colors.white.withOpacity(.7),
                              letterSpacing: 2,
                            ),
                          ),
                          const SizedBox(height: 3),
                          ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 280),
                            child: Text(title, style: AppText.display(size: 21, color: Colors.white)),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 20, 22, 0),
            child: Container(
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(color: AppColors.green50, borderRadius: BorderRadius.circular(14)),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.wifi_off_rounded, size: 17, color: AppColors.green700),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      _filipino
                          ? 'Naka-save ang materyal na ito para mabasa kahit walang internet.'
                          : 'This material is saved for offline reading — you can open it anytime, even without an internet connection.',
                      style: AppText.body(size: 11.5, color: AppColors.green900, height: 1.5),
                    ),
                  ),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 32),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final section in sections) _SectionBlock(section: section),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Small English / Filipino segmented toggle shown in the reader header,
/// so a resident can switch a single article's language without leaving
/// the page (independent of their saved Profile preference).
class _LanguageToggle extends StatelessWidget {
  final bool filipino;
  final ValueChanged<bool> onChanged;

  const _LanguageToggle({required this.filipino, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.1),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white.withOpacity(.16)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _segment('EN', !filipino, () => onChanged(false)),
          _segment('FIL', filipino, () => onChanged(true)),
        ],
      ),
    );
  }

  Widget _segment(String label, bool active, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
        decoration: BoxDecoration(
          color: active ? Colors.white : Colors.transparent,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          label,
          style: AppText.display(
            size: 11,
            weight: FontWeight.w700,
            color: active ? AppColors.green700 : Colors.white,
            letterSpacing: .5,
          ),
        ),
      ),
    );
  }
}

class _SectionBlock extends StatelessWidget {
  final ArticleSection section;
  const _SectionBlock({required this.section});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(section.heading, style: AppText.display(size: 15, color: AppColors.green900)),
          const SizedBox(height: 8),
          if (section.body != null)
            Text(section.body!, style: AppText.body(size: 13, color: AppColors.ink, height: 1.7)),
          if (section.bullets != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: section.bullets!
                  .map((b) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding: const EdgeInsets.only(top: 6, right: 10),
                              child: Container(
                                width: 6,
                                height: 6,
                                decoration: const BoxDecoration(color: AppColors.green700, shape: BoxShape.circle),
                              ),
                            ),
                            Expanded(
                              child: Text(b, style: AppText.body(size: 13, color: AppColors.ink, height: 1.6)),
                            ),
                          ],
                        ),
                      ))
                  .toList(),
            ),
        ],
      ),
    );
  }
}
