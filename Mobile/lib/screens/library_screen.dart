import 'package:flutter/material.dart';
import '../data/safety_files.dart';
import '../models/info_material.dart';
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';
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
                    // Hotlines are compiled into the app, so they are genuinely
                    // available with no signal. Nothing to download.
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
              _LibItem(articleKey: 'cpr', pages: filipino ? '4 na pahina' : '4 pages', filipino: filipino),
              _LibItem(articleKey: 'burns', pages: filipino ? '3 pahina' : '3 pages', filipino: filipino),
              _LibItem(articleKey: 'wound_care', pages: filipino ? '2 pahina' : '2 pages', filipino: filipino),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Paghahanda sa Sakuna' : 'Disaster Preparedness'),
              _LibItem(articleKey: 'before', pages: filipino ? '5 pahina' : '5 pages', filipino: filipino),
              _LibItem(articleKey: 'during', pages: filipino ? '4 na pahina' : '4 pages', filipino: filipino),
              _LibItem(articleKey: 'after', pages: filipino ? '4 na pahina' : '4 pages', filipino: filipino),
              _LibItem(
                articleKey: 'drrm_plan',
                pages: filipino ? 'Buod · 4 bahagi' : 'Summary · 4 sections',
                filipino: filipino,
              ),
              _LibItem(
                articleKey: 'evacuation_map',
                pages: filipino ? 'Buod · 4 bahagi' : 'Summary · 4 sections',
                filipino: filipino,
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
          child: _PublishedMaterials(appState: appState, filipino: filipino),
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
/// the [ArticleReaderScreen], in the selected language.
///
/// Its pill is always "Saved" and never tappable: these articles are compiled
/// into the app, so they are readable with no signal and there is nothing to
/// download. The old per-row `saved:` literals said otherwise for four of the
/// eight rows, which was simply wrong.
class _LibItem extends StatelessWidget {
  final String articleKey;
  final String pages;
  final bool filipino;

  const _LibItem({
    required this.articleKey,
    required this.pages,
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
                OfflinePill(saved: true, filipino: filipino),
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

/// Materials published by MDRRMO through the admin panel, fetched from
/// `GET /info-materials`. Before this section existed the endpoint had no
/// caller at all, so an advisory published during a live disaster could not
/// reach a resident without an app release.
class _PublishedMaterials extends StatelessWidget {
  final AppState appState;
  final bool filipino;

  const _PublishedMaterials({required this.appState, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final materials = appState.materials;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: filipino ? 'Mga Dokumento ng MDRRMO' : 'MDRRMO Documents',
        ),
        if (appState.materialsLoading && materials.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 18),
            child: Center(
              child: SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          )
        else if (materials.isEmpty)
          _Notice(
            text: appState.materialsError ??
                (filipino
                    ? 'Wala pang nakalathalang dokumento.'
                    : 'No documents published yet.'),
            onRetry: appState.materialsError == null
                ? null
                : () => appState.loadMaterials(),
            filipino: filipino,
          )
        else ...[
          if (appState.materialsFromCache)
            _Notice(
              text: filipino
                  ? 'Naka-save na kopya ang ipinapakita. Hindi maabot ang server.'
                  : 'Showing your saved copies. The server could not be reached.',
              onRetry: () => appState.loadMaterials(),
              filipino: filipino,
            ),
          for (final material in materials)
            _MaterialRow(
              material: material,
              appState: appState,
              filipino: filipino,
            ),
        ],
      ],
    );
  }
}

class _MaterialRow extends StatelessWidget {
  final InfoMaterial material;
  final AppState appState;
  final bool filipino;

  const _MaterialRow({
    required this.material,
    required this.appState,
    required this.filipino,
  });

  Future<void> _save(BuildContext context) async {
    final saved = await appState.saveMaterialOffline(material);
    if (!context.mounted) {
      return;
    }

    // Only claim success on a true. The old pill announced "saved for offline
    // use" unconditionally, having written nothing at all.
    showAppSnackBar(
      context,
      saved
          ? (filipino
              ? '${material.title} ay na-save para sa offline na gamit.'
              : '${material.title} saved for offline use.')
          : (filipino
              ? 'Hindi ma-download ang ${material.title}.'
              : 'Could not download ${material.title}.'),
    );
  }

  /// Tapping the row displays the material. The saved copy is handed to the
  /// platform viewer; without one, the server copy opens in the browser.
  Future<void> _open(BuildContext context) async {
    final result = await appState.openMaterial(material);
    if (!context.mounted) {
      return;
    }

    switch (result) {
      case MaterialOpenResult.openedSaved:
      case MaterialOpenResult.openedOnline:
        // Something else is on screen now; a snackbar under it would only be
        // read after the resident comes back.
        return;
      case MaterialOpenResult.noViewer:
        showAppSnackBar(
          context,
          filipino
              ? 'Walang app sa teleponong ito na makakabukas ng ${material.typeLabel}.'
              : 'No app on this phone can open a ${material.typeLabel}.',
          isError: true,
        );
      case MaterialOpenResult.unavailable:
        showAppSnackBar(
          context,
          filipino
              ? 'Hindi mabuksan ang ${material.title}. I-save ito habang may koneksyon.'
              : 'Could not open ${material.title}. Save it while you have a connection.',
          isError: true,
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final saved = appState.isSavedOffline(material.id);
    final saving = appState.isSavingOffline(material.id);
    final meta = [
      material.typeLabel,
      if (material.sizeLabel.isNotEmpty) material.sizeLabel,
    ].join(' · ');

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
          onTap: () => _open(context),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Row(
              children: [
                IconBadge(
                  icon: material.icon,
                  bg: AppColors.green50,
                  fg: AppColors.green700,
                  size: 40,
                  iconSize: 19,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        material.title,
                        style: AppText.display(size: 13, weight: FontWeight.w600),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        meta,
                        style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                // Web has nowhere to write, so it gets no download affordance at
                // all rather than a button that can only fail.
                if (appState.canSaveOffline) ...[
                  OfflinePill(
                    saved: saved,
                    loading: saving,
                    filipino: filipino,
                    onTap: saved ? null : () => _save(context),
                  ),
                  const SizedBox(width: 6),
                ],
                const Icon(
                  Icons.chevron_right_rounded,
                  size: 18,
                  color: AppColors.inkFaint,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  final String text;
  final VoidCallback? onRetry;
  final bool filipino;

  const _Notice({required this.text, this.onRetry, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 9),
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              text,
              style: AppText.body(size: 12, color: AppColors.inkMuted),
            ),
          ),
          if (onRetry != null) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: onRetry,
              child: Text(
                filipino ? 'Subukan muli' : 'Retry',
                style: AppText.display(
                  size: 12,
                  weight: FontWeight.w700,
                  color: AppColors.green700,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
