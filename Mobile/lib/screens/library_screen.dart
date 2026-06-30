import 'package:flutter/material.dart';
import '../data/library_articles.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'library/article_reader_screen.dart';

class LibraryScreen extends StatelessWidget {
  final AppState appState;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const LibraryScreen({
    super.key,
    required this.appState,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  Widget build(BuildContext context) {
    final filipino = appState.language == AppLanguage.filipino;

    return ListView(
      padding: EdgeInsets.zero,
      children: [
        AppHeader(onNotificationsTap: onOpenNotifications, onProfileTap: onOpenProfile),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SectionHeader(title: filipino ? 'Aklatan ng Kaligtasan' : 'Safety Library'),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: AppCard(
            leftAccent: AppColors.red600,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const IconBadge(icon: Icons.call_rounded, bg: AppColors.red50, fg: AppColors.red600),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        filipino ? 'Mga Hotline ng Emerhensiya' : 'Emergency Hotlines',
                        style: AppText.display(size: 14.5),
                      ),
                    ),
                    OfflinePill(saved: true, filipino: filipino),
                  ],
                ),
                const SizedBox(height: 12),
                _hotlineRow(context, 'MDRRMO', '0917-123-4567'),
                _hotlineRow(context, filipino ? 'Pulis (PNP)' : 'Police (PNP)', '117'),
                _hotlineRow(context, filipino ? 'Bumbero (BFP)' : 'Fire (BFP)', '116'),
                _hotlineRow(context, filipino ? 'Pambansang Emerhensiya' : 'National Emergency', '911'),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Pangunahing Lunas (First Aid)' : 'Basic First Aid'),
              _LibItem(articleKey: 'cpr', pages: filipino ? '4 na pahina' : '4 pages', saved: true, filipino: filipino),
              _LibItem(articleKey: 'burns', pages: filipino ? '3 pahina' : '3 pages', saved: true, filipino: filipino),
              _LibItem(articleKey: 'wound_care', pages: filipino ? '2 pahina' : '2 pages', saved: true, filipino: filipino),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Paghahanda sa Sakuna' : 'Disaster Preparedness'),
              _LibItem(articleKey: 'before', pages: filipino ? '5 pahina' : '5 pages', saved: true, filipino: filipino),
              _LibItem(articleKey: 'during', pages: filipino ? '4 na pahina' : '4 pages', saved: false, filipino: filipino),
              _LibItem(articleKey: 'after', pages: filipino ? '4 na pahina' : '4 pages', saved: false, filipino: filipino),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Mga Dokumento ng MDRRMO' : 'MDRRMO Documents'),
              _LibItem(
                articleKey: 'drrm_plan',
                pages: filipino ? 'Buod · 4 bahagi' : 'Summary · 4 sections',
                saved: false,
                filipino: filipino,
              ),
              _LibItem(
                articleKey: 'evacuation_map',
                pages: filipino ? 'Buod · 4 bahagi' : 'Summary · 4 sections',
                saved: true,
                filipino: filipino,
              ),
            ],
          ),
        ),
        const SizedBox(height: 110),
      ],
    );
  }

  Widget _hotlineRow(BuildContext context, String label, String number) {
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () => showAppSnackBar(context, 'Calling $label · $number'),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Expanded(child: Text(label, style: AppText.display(size: 12.5, weight: FontWeight.w600))),
            Icon(Icons.call_rounded, size: 13, color: AppColors.green700),
            const SizedBox(width: 6),
            Text(number, style: AppText.display(size: 12.5, weight: FontWeight.w700, color: AppColors.green700)),
          ],
        ),
      ),
    );
  }
}

/// A tappable library item — opens the corresponding [LibraryArticle] in
/// the [ArticleReaderScreen], in the selected language. The trailing
/// [OfflinePill] still toggles the saved/download state independently of
/// the tap-to-read action.
class _LibItem extends StatelessWidget {
  final String articleKey;
  final String pages;
  final bool saved;
  final bool filipino;

  const _LibItem({
    required this.articleKey,
    required this.pages,
    required this.saved,
    required this.filipino,
  });

  @override
  Widget build(BuildContext context) {
    final article = libraryArticles[articleKey]!;
    final title = article.titleFor(filipino: filipino);
    final subtitle = article.subtitleFor(filipino: filipino);

    return Container(
      margin: const EdgeInsets.only(bottom: 9),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => ArticleReaderScreen(article: article, filipino: filipino)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Row(
              children: [
                IconBadge(icon: article.icon, bg: article.iconBg, fg: article.iconFg, size: 40, iconSize: 19),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: AppText.display(size: 13, weight: FontWeight.w600)),
                      const SizedBox(height: 2),
                      Text('$subtitle · $pages', style: AppText.body(size: 11.5, color: AppColors.inkMuted)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                OfflinePill(saved: saved, label: title, filipino: filipino),
                const SizedBox(width: 6),
                const Icon(Icons.chevron_right_rounded, size: 18, color: AppColors.inkFaint),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
