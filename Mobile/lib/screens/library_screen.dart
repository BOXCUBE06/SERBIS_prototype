import 'package:flutter/material.dart';
import '../data/safety_files.dart';
import '../models/info_material.dart';
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';
import '../widgets/hotline_list.dart';
import 'library/article_reader_screen.dart';

class LibraryScreen extends StatelessWidget {
  final AppState appState;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  /// Set when the Library is opened as a page over the tabs (from Home's Safety
  /// guides card); draws the header's back button.
  final VoidCallback? onBack;

  const LibraryScreen({
    super.key,
    required this.appState,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.onBack,
  });

  @override
  Widget build(BuildContext context) {
    final filipino = appState.language == AppLanguage.filipino;

    // First Aid and Disaster Preparedness are compiled into the app. A pull
    // refetches MDRRMO Documents (_PublishedMaterials) and the hotlines.
    return RefreshIndicator(
      onRefresh: () => Future.wait([appState.loadMaterials(), appState.loadHotlines()]),
      child: ListView(
      padding: EdgeInsets.zero,
      children: [
        AppHeader(
          onNotificationsTap: onOpenNotifications,
          onProfileTap: onBack == null ? onOpenProfile : null,
          onBack: onBack,
          filipino: filipino,
        ),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppLayout.gutter),
          child: SectionHeader(title: filipino ? 'Aklatan ng kaligtasan' : 'Safety library'),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppLayout.gutter),
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
                        style: AppText.display(size: AppTextSize.bodyLg),
                      ),
                    ),
                    // Always on the device: the cached server list, or the
                    // built-in one before the first fetch. Nothing to download.
                    OfflinePill(saved: true, filipino: filipino),
                  ],
                ),
                const SizedBox(height: 12),
                HotlineList(hotlines: appState.hotlines, filipino: filipino),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 22, AppLayout.gutter, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Pangunahing lunas (First Aid)' : 'Basic first aid'),
              _LibItem(articleKey: 'cpr', pages: filipino ? '4 na pahina' : '4 pages', filipino: filipino),
              _LibItem(articleKey: 'burns', pages: filipino ? '3 pahina' : '3 pages', filipino: filipino),
              _LibItem(articleKey: 'wound_care', pages: filipino ? '2 pahina' : '2 pages', filipino: filipino),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 22, AppLayout.gutter, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: filipino ? 'Paghahanda sa sakuna' : 'Disaster preparedness'),
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
          padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 22, AppLayout.gutter, 0),
          child: _PublishedMaterials(appState: appState, filipino: filipino),
        ),
        const SizedBox(height: AppLayout.navClearance),
      ],
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
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
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
                      Text(title, style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600)),
                      const SizedBox(height: 2),
                      Text('$subtitle · $pages', style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
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
          title: filipino ? 'Mga dokumento ng MDRRMO' : 'MDRRMO documents',
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
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
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
                        style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
                      ),
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              meta,
                              style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                            ),
                          ),
                          // Only drawn when true. There is no "unverified"
                          // badge: absence is not a warning about the file, it
                          // is MDRRMO not having got to it yet.
                          if (material.verified) ...[
                            const SizedBox(width: 6),
                            _VerifiedBadge(
                              filipino: filipino,
                              byName: material.verifiedByName,
                              byRole: material.verifiedByRole,
                            ),
                          ],
                        ],
                      ),
                      // Named on the row, not only in the badge's tooltip, which
                      // a touch screen shows on a long-press nobody knows to do.
                      if (material.verifierLabel case final who?) ...[
                        const SizedBox(height: 2),
                        Text(
                          filipino ? 'Sinuri ni $who' : 'Verified by $who',
                          style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                        ),
                      ],
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

/// "MDRRMO has checked this", on the row rather than behind a tap: a resident
/// deciding whether to act on an advisory is deciding it here.
class _VerifiedBadge extends StatelessWidget {
  final bool filipino;

  /// Who verified it, and in what capacity — MDRRMO feedback, 2026-09-18:
  /// "verified" with nobody named behind it is what this replaces. Shown as
  /// the badge's tooltip: the list row has no room for a name and a role
  /// beside everything else on it, but the fact is still reachable from the
  /// same badge, not buried behind a second screen.
  final String? byName;
  final String? byRole;

  const _VerifiedBadge({required this.filipino, this.byName, this.byRole});

  @override
  Widget build(BuildContext context) {
    final who = [byName, byRole].where((s) => s != null && s.isNotEmpty).join(' — ');

    return Tooltip(
      message: who.isEmpty
          ? (filipino ? 'Sinuri ng MDRRMO' : 'Checked by MDRRMO')
          : (filipino ? 'Sinuri ni $who' : 'Verified by $who'),
      child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: AppColors.green50, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.verified_rounded, size: 11, color: AppColors.green700),
          const SizedBox(width: 3),
          Text(
            filipino ? 'Beripikado' : 'Verified',
            style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w700, color: AppColors.green700),
          ),
        ],
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
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              text,
              style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
            ),
          ),
          if (onRetry != null) ...[
            const SizedBox(width: 8),
            TextButton(
              onPressed: onRetry,
              child: Text(
                filipino ? 'Subukan muli' : 'Retry',
                style: AppText.display(
                  size: AppTextSize.small,
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
