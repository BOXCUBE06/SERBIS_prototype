import 'package:flutter/material.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/shared_widgets.dart';
import 'borrow_equipment_screen.dart';

class HomeScreen extends StatelessWidget {
  final AppState appState;
  final VoidCallback onOpenTrack;
  final VoidCallback onOpenLibrary;
  final VoidCallback onOpenProfile;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenServices;
  final ValueChanged<ServiceType> onOpenService;

  const HomeScreen({
    super.key,
    required this.appState,
    required this.onOpenTrack,
    required this.onOpenLibrary,
    required this.onOpenProfile,
    required this.onOpenNotifications,
    required this.onOpenServices,
    required this.onOpenService,
  });

  @override
  Widget build(BuildContext context) {
    final f = appState.language == AppLanguage.filipino;
    final activeRequest = appState.activeRequest;

    final list = ListView(
      padding: EdgeInsets.zero,
      // See TrackScreen: stated so the pull survives this list being given a
      // controller and losing `primary`.
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        AppHeader(onNotificationsTap: onOpenNotifications, onProfileTap: onOpenProfile),
        const SizedBox(height: 22),

        // ── Active Service Request ──
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(
                title: tr(f, 'home.active_request'),
                actionLabel: activeRequest == null ? null : tr(f, 'common.view_all'),
                onAction: onOpenTrack,
              ),
              if (activeRequest == null)
                AppCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const IconBadge(
                            icon: Icons.assignment_outlined,
                            bg: AppColors.green50,
                            fg: AppColors.green700,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(tr(f, 'home.no_active_title'), style: AppText.display(size: 14.5)),
                                const SizedBox(height: 2),
                                Text(
                                  tr(f, 'home.no_active_desc'),
                                  style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.5),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      AppButton(label: tr(f, 'home.submit_a_request'), onPressed: onOpenServices),
                    ],
                  ),
                )
              else
                AppCard(
                  leftAccent: AppColors.blue600,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          IconBadge(
                            icon: activeRequest.displayIcon,
                            bg: activeRequest.displayBg,
                            fg: activeRequest.displayFg,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(activeRequest.displayTitle(f), style: AppText.display(size: 14.5)),
                                const SizedBox(height: 2),
                                Text(
                                    activeRequest.refNo.isEmpty
                                        ? (f ? 'Naghihintay ng reference number' : 'Reference number pending')
                                        : 'Ref #${activeRequest.refNo}',
                                    style: AppText.body(size: 11.5, color: AppColors.inkMuted)),
                              ],
                            ),
                          ),
                          StatusBadge(activeRequest.status, filipino: f),
                        ],
                      ),
                      const SizedBox(height: 14),
                      ...activeRequest.metaLines.map(
                        (m) => Padding(
                          padding: const EdgeInsets.only(bottom: 6),
                          child: Row(
                            children: [
                              const Icon(Icons.place_outlined, size: 14, color: AppColors.inkFaint),
                              const SizedBox(width: 8),
                              Expanded(child: Text(m, style: AppText.body(size: 12.5))),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 6),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(11),
                        decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(10)),
                        child: Text(
                          activeRequest.note ?? _statusMessage(f, activeRequest.status),
                          style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.6),
                        ),
                      ),
                      const SizedBox(height: 14),
                      Row(
                        children: [
                          Expanded(child: AppButton(label: tr(f, 'common.view_details'), onPressed: onOpenTrack)),
                          if (activeRequest.cancellable) ...[
                            const SizedBox(width: 10),
                            Expanded(
                              child: AppButton(
                                label: tr(f, 'common.cancel'),
                                style: AppButtonStyle.outline,
                                onPressed: () => showCancelDialog(
                                  context,
                                  activeRequest.refNo,
                                  () => appState.cancelRequest(activeRequest.id),
                                  filipino: f,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),

        const SizedBox(height: 22),

        // ── Need help now ──
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(title: tr(f, 'home.need_help_now')),
              Row(
                children: [
                  Expanded(
                    child: _QuickTypeCard(
                      icon: ServiceType.ambulance.icon,
                      bg: ServiceType.ambulance.bg,
                      fg: ServiceType.ambulance.fg,
                      title: ServiceType.ambulance.titleFor(f),
                      subtitle: ServiceType.ambulance.subtitleFor(f),
                      onTap: () => onOpenService(ServiceType.ambulance),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _QuickTypeCard(
                      icon: Icons.inventory_2_outlined,
                      bg: AppColors.green50,
                      fg: AppColors.green700,
                      title: 'Borrow Equipment',
                      subtitle: 'Wheelchairs, stretchers & more',
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) =>
                                BorrowEquipmentScreen(appState: appState)),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),

        const SizedBox(height: 22),

        // ── Announcements ──
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SectionHeader(
                title: tr(f, 'home.announcements'),
                actionLabel: tr(f, 'home.info_center'),
                onAction: onOpenLibrary,
              ),
              // What MDRRMO has actually published, newest first. This section
              // used to be two fixed tiles with invented timestamps ("Today ·
              // 8:12 AM") and one permanently flagged NEW, so a resident who
              // opened the app during a flood read a weather advisory written
              // months earlier in a translation file.
              ..._announcements(f),
            ],
          ),
        ),
        const SizedBox(height: 110),
      ],
    );

    return RefreshIndicator(
      color: AppColors.green700,
      onRefresh: () => appState.loadRequests(),
      child: list,
    );
  }

  /// The two most recent published materials, or an honest line about why
  /// there are none. Home shows a short list; the Library shows all of them,
  /// which is what the section header's action opens.
  List<Widget> _announcements(bool f) {
    if (appState.materials.isEmpty) {
      if (appState.materialsLoading) {
        return const [SizedBox.shrink()];
      }
      return [
        _AnnouncementNote(
          text: appState.materialsError == null
              ? tr(f, 'home.ann.empty')
              : tr(f, 'home.ann.failed'),
        ),
      ];
    }

    // The server sends them newest-first, but the offline index is ordered by
    // download time, so sort here rather than trust either.
    final latest = [...appState.materials]..sort((a, b) {
        final left = a.publishedAt;
        final right = b.publishedAt;
        if (left == null && right == null) return 0;
        // A material with no date sinks: it cannot be claimed to be recent.
        if (left == null) return 1;
        if (right == null) return -1;
        return right.compareTo(left);
      });

    return [
      for (final material in latest.take(2))
        _AnnouncementTile(
          icon: material.icon,
          iconBg: AppColors.green50,
          iconFg: AppColors.green700,
          title: material.title,
          time: material.publishedAt == null
              ? tr(f, 'home.ann.no_date')
              : formatTimelineTime(material.publishedAt!, f),
          desc: [material.typeLabel, material.sizeLabel]
              .where((part) => part.isNotEmpty)
              .join(' · '),
          onTap: onOpenLibrary,
        ),
      if (appState.materialsFromCache)
        _AnnouncementNote(text: tr(f, 'home.ann.offline')),
    ];
  }

  String _statusMessage(bool f, ReqStatus status) => switch (status) {
        ReqStatus.review => tr(f, 'home.status.review'),
        ReqStatus.booked => tr(f, 'home.status.booked'),
        ReqStatus.scheduled => tr(f, 'home.status.scheduled'),
        ReqStatus.completed => tr(f, 'home.status.completed'),
        ReqStatus.cancelled => tr(f, 'home.status.cancelled'),
        ReqStatus.disapproved => tr(f, 'home.status.disapproved'),
      };
}

class _QuickTypeCard extends StatelessWidget {
  final IconData icon;
  final Color bg;
  final Color fg;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const _QuickTypeCard({
    required this.icon,
    required this.bg,
    required this.fg,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.line, width: 1.5),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            IconBadge(icon: icon, bg: bg, fg: fg),
            const SizedBox(height: 10),
            Text(title,
                style: AppText.display(size: 12.5, weight: FontWeight.w600),
                maxLines: 2),
            const SizedBox(height: 2),
            Text(subtitle,
                style: AppText.body(size: 11, color: AppColors.inkMuted)),
          ],
        ),
      ),
    );
  }
}

class _AnnouncementTile extends StatelessWidget {
  final IconData icon;
  final Color iconBg;
  final Color iconFg;
  final String title;
  final String time;
  final String desc;
  final VoidCallback? onTap;

  const _AnnouncementTile({
    required this.icon,
    required this.iconBg,
    required this.iconFg,
    required this.title,
    required this.time,
    required this.desc,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconBadge(icon: icon, bg: iconBg, fg: iconFg, size: 36, iconSize: 17, radius: 10),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // The NEW badge that used to sit here was hardcoded true, so it
                // never came off. A flag that is always on is not information.
                Text(title, style: AppText.display(size: 13, weight: FontWeight.w600)),
                const SizedBox(height: 3),
                Text(time, style: AppText.body(size: 11, color: AppColors.inkFaint)),
                const SizedBox(height: 4),
                Text(desc, style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.5)),
              ],
            ),
          ),
        ],
      ),
      ),
    );
  }
}

/// A one-line explanation in place of, or under, the announcement tiles: no
/// materials published yet, the fetch failed, or these are saved copies.
class _AnnouncementNote extends StatelessWidget {
  final String text;

  const _AnnouncementNote({required this.text});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Text(text, style: AppText.body(size: 12, color: AppColors.inkMuted)),
    );
  }
}
