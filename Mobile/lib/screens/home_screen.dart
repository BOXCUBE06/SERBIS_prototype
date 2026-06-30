import 'package:flutter/material.dart';
import '../models/models.dart';
import '../state/app_state.dart';
import '../state/strings.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

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

    return ListView(
      padding: EdgeInsets.zero,
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
                            icon: activeRequest.type.icon,
                            bg: activeRequest.type.bg,
                            fg: activeRequest.type.fg,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(activeRequest.type.titleFor(f), style: AppText.display(size: 14.5)),
                                const SizedBox(height: 2),
                                Text('Ref #${activeRequest.refNo}',
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
                                  () => appState.cancelRequest(activeRequest.refNo),
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
                      type: ServiceType.ambulance,
                      filipino: f,
                      onTap: () => onOpenService(ServiceType.ambulance),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _QuickTypeCard(
                      type: ServiceType.relief,
                      filipino: f,
                      onTap: () => onOpenService(ServiceType.relief),
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
              _AnnouncementTile(
                icon: Icons.download_rounded,
                iconBg: AppColors.green50,
                iconFg: AppColors.green700,
                title: tr(f, 'home.ann1.title'),
                isNew: true,
                time: tr(f, 'home.ann1.time'),
                desc: tr(f, 'home.ann1.desc'),
              ),
              _AnnouncementTile(
                icon: Icons.campaign_outlined,
                iconBg: AppColors.amber50,
                iconFg: AppColors.amber600,
                title: tr(f, 'home.ann2.title'),
                time: tr(f, 'home.ann2.time'),
                desc: tr(f, 'home.ann2.desc'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 110),
      ],
    );
  }

  String _statusMessage(bool f, ReqStatus status) => switch (status) {
        ReqStatus.review => tr(f, 'home.status.review'),
        ReqStatus.scheduled => tr(f, 'home.status.scheduled'),
        ReqStatus.completed => tr(f, 'home.status.completed'),
        ReqStatus.cancelled => tr(f, 'home.status.cancelled'),
      };
}

class _QuickTypeCard extends StatelessWidget {
  final ServiceType type;
  final bool filipino;
  final VoidCallback onTap;

  const _QuickTypeCard({required this.type, required this.filipino, required this.onTap});

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
            IconBadge(icon: type.icon, bg: type.bg, fg: type.fg),
            const SizedBox(height: 10),
            Text(type.titleFor(filipino), style: AppText.display(size: 12.5, weight: FontWeight.w600), maxLines: 2),
            const SizedBox(height: 2),
            Text(type.subtitleFor(filipino), style: AppText.body(size: 11, color: AppColors.inkMuted)),
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
  final bool isNew;

  const _AnnouncementTile({
    required this.icon,
    required this.iconBg,
    required this.iconFg,
    required this.title,
    required this.time,
    required this.desc,
    this.isNew = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
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
                Row(
                  children: [
                    Expanded(child: Text(title, style: AppText.display(size: 13, weight: FontWeight.w600))),
                    if (isNew)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                        decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(6)),
                        child: Text('NEW', style: AppText.display(size: 9, weight: FontWeight.w700, color: AppColors.red600, letterSpacing: .5)),
                      ),
                  ],
                ),
                const SizedBox(height: 3),
                Text(time, style: AppText.body(size: 11, color: AppColors.inkFaint)),
                const SizedBox(height: 4),
                Text(desc, style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.5)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
