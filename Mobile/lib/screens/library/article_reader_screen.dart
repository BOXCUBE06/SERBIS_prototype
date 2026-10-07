import 'package:flutter/material.dart';
import '../../data/safety_files.dart';
import '../../state/translations.dart';
import '../../theme/app_theme.dart';
import '../../widgets/shared_widgets.dart' show HeaderButton;
import '../../widgets/status_line.dart';

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
    final f = _filipino;
    final title = article.titleFor(filipino: f);
    final subtitle = article.subtitleFor(filipino: f);
    final sections = article.sectionsFor(filipino: f);

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(8, AppLayout.headerTop, 14, 20),
            decoration: const BoxDecoration(
              color: AppColors.header,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.header)),
            ),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 600),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        HeaderButton(
                          icon: Icons.arrow_back_rounded,
                          label: tr(f, 'nav.back'),
                          onTap: () => Navigator.pop(context),
                        ),
                        Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: .14),
                            borderRadius: BorderRadius.circular(AppRadius.md),
                          ),
                          alignment: Alignment.center,
                          child: Icon(article.icon, color: Colors.white, size: 20),
                        ),
                        const Spacer(),
                        _LanguageToggle(filipino: f, onChanged: (v) => setState(() => _filipino = v)),
                      ],
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(AppLayout.gutter - 8, 8, 0, 0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            subtitle,
                            style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .88)),
                          ),
                          const SizedBox(height: 2),
                          Semantics(
                            header: true,
                            child: Text(title, style: AppText.display(size: AppTextSize.headline, color: Colors.white)),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 600),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // A quiet line, not a box: being readable offline is good
                    // news, not something to act on.
                    StatusLine(label: tr(f, 'article.offline_title'), tone: StatusTone.green),
                    const SizedBox(height: AppSpacing.xs),
                    Text(
                      f
                          ? 'Naka-save ang materyal na ito para mabasa kahit walang internet.'
                          : 'This material is saved for offline reading — you can open it anytime, even without an internet connection.',
                      style: AppText.detail(),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    for (final section in sections) _SectionBlock(section: section),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// EN / FIL, two 44dp targets in one pill on the header.
class _LanguageToggle extends StatelessWidget {
  final bool filipino;
  final ValueChanged<bool> onChanged;

  const _LanguageToggle({required this.filipino, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: Colors.white.withValues(alpha: .16)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _segment('EN', 'English', !filipino, () => onChanged(false)),
          _segment('FIL', 'Filipino', filipino, () => onChanged(true)),
        ],
      ),
    );
  }

  Widget _segment(String label, String name, bool active, VoidCallback onTap) {
    return Semantics(
      button: true,
      selected: active,
      label: name,
      excludeSemantics: true,
      child: Material(
        color: active ? Colors.white : Colors.transparent,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(AppRadius.pill),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 52, minHeight: 44),
            child: Center(
              child: Text(
                label,
                style: AppText.display(
                  size: AppTextSize.small,
                  weight: FontWeight.w600,
                  color: active ? AppColors.green700 : Colors.white,
                  letterSpacing: .5,
                ),
              ),
            ),
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
      padding: const EdgeInsets.only(bottom: AppSpacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(
              section.heading,
              style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: AppColors.sectionInk),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          if (section.body != null)
            Text(section.body!, style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.ink, height: 1.6)),
          if (section.bullets != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: section.bullets!
                  .map((b) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding: const EdgeInsets.only(top: 9, right: 12),
                              child: Container(
                                width: 6,
                                height: 6,
                                decoration: const BoxDecoration(color: AppColors.green700, shape: BoxShape.circle),
                              ),
                            ),
                            Expanded(
                              child: Text(b, style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.ink, height: 1.6)),
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
