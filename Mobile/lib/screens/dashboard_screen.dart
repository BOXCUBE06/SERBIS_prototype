import 'dart:math' show pi;

import 'package:flutter/material.dart';
import '../data/hotlines.dart';
import '../models/request_models.dart';
import '../state/account_store.dart' show AppUser;
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/borrow_request_widgets.dart' show mdrrmoNumber;
import '../widgets/feedback.dart';
import '../widgets/loading.dart';
import '../widgets/motion.dart';
import '../widgets/request_summary.dart';
import '../widgets/shared_widgets.dart';
import '../widgets/status_line.dart';
import 'borrow_equipment_screen.dart';

/// Home's side margin, from the redesign (C_Home on the design canvas).
const double _gutter = 16;

/// "Open in Track" on the green header; no theme token is this light.
const Color _headerLink = Color(0xFFBFE3CD);

class HomeScreen extends StatelessWidget {
  final AppState appState;

  /// Threaded through to [BorrowEquipmentScreen] for its delivery-address
  /// "Same as my address" checkbox, and read for the greeting.
  final AppUser user;
  final VoidCallback onOpenTrack;
  final VoidCallback onOpenLibrary;
  final VoidCallback onOpenProfile;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenServices;
  final ValueChanged<ServiceType> onOpenService;

  /// Opens the Borrow tab. Left out, the tile pushes the borrowing screen
  /// instead, which is what a host with no Borrow tab wants.
  final VoidCallback? onOpenBorrow;

  /// Opens Borrow → My requests, for a loan in the header panel. Left out, it
  /// opens Borrow the way [onOpenBorrow] does.
  final VoidCallback? onOpenMyLoans;

  const HomeScreen({
    super.key,
    required this.appState,
    required this.user,
    required this.onOpenTrack,
    required this.onOpenLibrary,
    required this.onOpenProfile,
    required this.onOpenNotifications,
    required this.onOpenServices,
    required this.onOpenService,
    this.onOpenBorrow,
    this.onOpenMyLoans,
  });

  void _openBorrow(BuildContext context) {
    if (onOpenBorrow != null) return onOpenBorrow!();
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => BorrowEquipmentScreen(appState: appState, user: user)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final f = appState.language == AppLanguage.filipino;
    final hotline = mdrrmoNumber(appState.hotlines);
    final active = openSummaries(appState, f);
    final latest = active.isEmpty ? null : active.first;

    final list = ListView(
      padding: EdgeInsets.zero,
      // See TrackScreen: stated so the pull survives this list being given a
      // controller and losing `primary`.
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        _HomeHeader(
          user: user,
          filipino: f,
          unread: appState.hasUnreadNotifications,
          onNotificationsTap: onOpenNotifications,
          onProfileTap: onOpenProfile,
          // Hidden when nothing is open: the tiles below are the way in then.
          latest: latest == null
              ? null
              : _LatestPanel(
                  request: latest,
                  more: active.length - 1,
                  filipino: f,
                  onTap: latest.isBorrow ? (onOpenMyLoans ?? () => _openBorrow(context)) : onOpenTrack,
                ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(_gutter, 16, _gutter, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (hotline != null) _EmergencyBar(filipino: f, onTap: () => callHotlineNumber(hotline)),
              // An organization MDRRMO has not activated yet cannot file
              // anything; it is told why instead of offered the tiles.
              if (user.isAwaitingApproval) ...[
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: AppColors.amber50, borderRadius: BorderRadius.circular(AppRadius.md)),
                  child: Text(tr(f, 'awaiting.title'), style: AppText.body(size: 15, color: AppColors.amberInk, height: 1.4)),
                ),
              ],
              const SizedBox(height: 24),
              SectionHeader(title: tr(f, 'home.services')),
              _TileGrid(tiles: [
                if (!user.isAwaitingApproval)
                  _Tile(
                    icon: Icons.airport_shuttle_outlined,
                    title: tr(f, 'home.tile.transport'),
                    subtitle: tr(f, 'home.tile.transport_desc'),
                    onTap: () => onOpenService(ServiceType.ambulance),
                  ),
                if (!user.isAwaitingApproval && appState.borrowingAllowed)
                  _Tile(
                    icon: Icons.inventory_2_outlined,
                    title: tr(f, 'borrow.title'),
                    subtitle: tr(f, 'home.tile.borrow_desc'),
                    onTap: () => _openBorrow(context),
                  ),
                // The Library: a reference, opened when wanted.
                _Tile(
                  icon: Icons.menu_book_outlined,
                  title: tr(f, 'home.safety_guides'),
                  subtitle: tr(f, 'home.tile.guides_desc'),
                  onTap: onOpenLibrary,
                ),
                if (!user.isAwaitingApproval)
                  _Tile(
                    icon: Icons.grid_view_outlined,
                    title: tr(f, 'home.tile.all'),
                    subtitle: tr(f, 'home.tile.all_desc'),
                    onTap: onOpenServices,
                  ),
              ]),
              const SizedBox(height: 20),
              SectionHeader(title: tr(f, 'home.announcements'), actionLabel: tr(f, 'home.see_all'), onAction: onOpenLibrary),
              ..._announcements(f),
            ],
          ),
        ),
        const SizedBox(height: AppLayout.navClearance),
      ],
    );

    return RefreshIndicator(
      color: AppColors.green700,
      onRefresh: () => Future.wait([
        appState.loadRequests(),
        if (!user.isAwaitingApproval && appState.borrowingAllowed) appState.loadBorrowRequests(),
      ]),
      // Phone-width on a tablet, the web build or a desktop window.
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 600), child: list),
      ),
    );
  }

  /// The two most recent published materials; their shape while loading; a
  /// retry when they could not load; one muted line when there are none. The
  /// Library shows all of them.
  List<Widget> _announcements(bool f) {
    if (appState.materials.isEmpty) {
      if (appState.materialsLoading) return [SkeletonRows(count: 2, filipino: f)];
      if (appState.materialsError != null) {
        return [
          LoadErrorBox(
            title: tr(f, 'home.ann.failed'),
            body: tr(f, 'tab.load_failed'),
            onRetry: appState.loadMaterials,
            filipino: f,
          ),
        ];
      }
      return [_MutedLine(tr(f, 'home.ann.empty'))];
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
      SummaryCard(children: [
        for (final material in latest.take(2))
          _AnnouncementRow(
            date: material.publishedAt,
            title: material.title,
            // Materials carry no body text; what they are is the next best line.
            body: [material.typeLabel, material.sizeLabel].where((part) => part.isNotEmpty).join(' · '),
            noDateLabel: tr(f, 'home.ann.no_date'),
            onTap: onOpenLibrary,
          ),
      ]),
      if (appState.materialsFromCache) ...[
        const SizedBox(height: 8),
        _MutedLine(tr(f, 'home.ann.offline')),
      ],
    ];
  }
}

String _greetingKey(DateTime now) => now.hour < 12
    ? 'home.greet.morning'
    : now.hour < 18
        ? 'home.greet.afternoon'
        : 'home.greet.evening';

class _HomeHeader extends StatelessWidget {
  final AppUser user;
  final bool filipino;
  final bool unread;
  final VoidCallback onNotificationsTap;
  final VoidCallback onProfileTap;

  /// The latest open request, or null when nothing is open.
  final Widget? latest;

  const _HomeHeader({
    required this.user,
    required this.filipino,
    required this.unread,
    required this.onNotificationsTap,
    required this.onProfileTap,
    required this.latest,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final white = Colors.white.withValues(alpha: .85);

    return Container(
      padding: const EdgeInsets.fromLTRB(_gutter, AppLayout.headerTop, 8, 22),
      decoration: const BoxDecoration(
        color: AppColors.header,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _SealSlot(label: tr(f, 'home.seal')),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  tr(f, 'home.brand'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppText.display(size: AppTextSize.detail, weight: FontWeight.w600, color: Colors.white.withValues(alpha: .9)),
                ),
              ),
              HeaderButton(
                icon: Icons.notifications_outlined,
                label: tr(f, unread ? 'nav.notifications_new' : 'nav.notifications'),
                dot: unread,
                onTap: onNotificationsTap,
              ),
              HeaderButton(icon: Icons.person_outline_rounded, label: tr(f, 'nav.profile'), onTap: onProfileTap),
            ],
          ),
          const SizedBox(height: 14),
          Text(tr(f, _greetingKey(DateTime.now())), style: AppText.body(size: 15, color: white)),
          const SizedBox(height: 2),
          Padding(
            padding: const EdgeInsets.only(right: _gutter - 8),
            child: Text(user.accountName, style: AppText.display(size: 26, weight: FontWeight.w600, color: Colors.white)),
          ),
          // A barangay hall or an organization also sees which kind of account
          // this phone is on, and who the contact person is.
          if (user.isOrganization || user.isBarangay)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                [tr(f, user.accountTypeKey), if (user.isOrganization && user.fullName.isNotEmpty) user.fullName].join(' · '),
                style: AppText.body(size: 15, color: white),
              ),
            ),
          if (latest != null) ...[
            const SizedBox(height: 18),
            Padding(padding: const EdgeInsets.only(right: _gutter - 8), child: latest!),
          ],
        ],
      ),
    );
  }
}

/// Where the official MDRRMO seal goes. Until the office sends the artwork it
/// is a dashed ring with the shield in it; swap the child for an `Image.asset`.
class _SealSlot extends StatelessWidget {
  final String label;
  const _SealSlot({required this.label});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: label,
      image: true,
      child: const CustomPaint(
        painter: _DashedRing(),
        child: SizedBox(
          width: 40,
          height: 40,
          child: Icon(Icons.shield_outlined, color: Colors.white, size: 18),
        ),
      ),
    );
  }
}

class _DashedRing extends CustomPainter {
  const _DashedRing();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Colors.white.withValues(alpha: .35)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    final rect = (Offset.zero & size).deflate(1);
    const dashes = 16;
    const sweep = 2 * pi / dashes;
    for (var i = 0; i < dashes; i++) {
      canvas.drawArc(rect, i * sweep, sweep * .6, false, paint);
    }
  }

  @override
  bool shouldRepaint(_DashedRing old) => false;
}

/// The resident's newest open request, on the green header: what it is, where
/// it stands, how far along, and when it last changed.
class _LatestPanel extends StatelessWidget {
  final RequestSummary request;

  /// Open requests besides this one.
  final int more;
  final bool filipino;
  final VoidCallback onTap;

  const _LatestPanel({required this.request, required this.more, required this.filipino, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final white = Colors.white.withValues(alpha: .85);
    final radius = BorderRadius.circular(16);
    final tab = tr(f, r.isBorrow ? 'nav.borrow' : 'nav.track');
    final meta = [
      if (r.updatedAt != null) StatusLine.updatedText(r.updatedAt!, f),
      if (more == 1) tr(f, 'home.more_one'),
      if (more > 1) tr(f, 'home.more_many').replaceAll('{n}', '$more'),
    ].join(' · ');

    return Material(
      color: Colors.white.withValues(alpha: .08),
      shape: RoundedRectangleBorder(borderRadius: radius, side: BorderSide(color: Colors.white.withValues(alpha: .16))),
      child: InkWell(
        onTap: onTap,
        borderRadius: radius,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(child: Text(tr(f, 'home.latest'), style: AppText.body(size: AppTextSize.detail, color: white))),
                  const SizedBox(width: 10),
                  // Flexible: the Filipino pair outgrows a 320dp phone on one line.
                  Flexible(
                    child: Text(
                      tr(f, 'home.open_in').replaceAll('{tab}', tab),
                      textAlign: TextAlign.end,
                      style: AppText.display(size: AppTextSize.detail, weight: FontWeight.w600, color: _headerLink),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(r.title, style: AppText.display(size: 17, weight: FontWeight.w600, color: Colors.white, height: 1.3)),
              const SizedBox(height: 12),
              StatusLine(label: r.status, tone: r.tone, large: true, onDark: true),
              if (r.steps > 0) ...[
                const SizedBox(height: 12),
                ExcludeSemantics(child: _ProgressBar(step: r.step, steps: r.steps, tone: r.tone)),
              ],
              if (meta.isNotEmpty) ...[
                const SizedBox(height: 12),
                Text(meta, style: AppText.detail(color: white)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// One segment per step: white for done, the status colour for the step in
/// progress, faint for what is still ahead.
class _ProgressBar extends StatelessWidget {
  final int step;
  final int steps;
  final StatusTone tone;

  const _ProgressBar({required this.step, required this.steps, required this.tone});

  @override
  Widget build(BuildContext context) {
    final (dot, _) = StatusLine.colorsOf(tone);
    return Row(
      children: [
        for (var i = 0; i < steps; i++) ...[
          if (i > 0) const SizedBox(width: 4),
          Expanded(
            child: Container(
              height: 6,
              decoration: BoxDecoration(
                color: i < step ? Colors.white : (i == step ? dot : Colors.white.withValues(alpha: .22)),
                borderRadius: BorderRadius.circular(3),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// Small, separate and always reachable; hidden when no MDRRMO number is known.
class _EmergencyBar extends StatelessWidget {
  final bool filipino;
  final VoidCallback onTap;

  const _EmergencyBar({required this.filipino, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      side: const BorderSide(color: AppColors.line),
    );
    return Material(
      color: AppColors.surface,
      shape: shape,
      child: InkWell(
        onTap: onTap,
        customBorder: shape,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 52),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              children: [
                const Icon(Icons.phone_outlined, size: 20, color: AppColors.red600),
                const SizedBox(width: 10),
                Expanded(
                  child: Text.rich(
                    TextSpan(children: [
                      TextSpan(text: '${tr(f, 'home.emergency')} '),
                      TextSpan(
                        text: tr(f, 'home.emergency.call'),
                        style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.red600),
                      ),
                    ]),
                    style: AppText.body(size: 15, height: 1.35),
                  ),
                ),
                const Icon(Icons.chevron_right_rounded, size: 20, color: AppColors.inkFaint),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Two tiles a row; an odd one out keeps its half width.
class _TileGrid extends StatelessWidget {
  final List<_Tile> tiles;

  const _TileGrid({required this.tiles});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < tiles.length; i += 2)
          Padding(
            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: tiles[i]),
                  const SizedBox(width: 10),
                  Expanded(child: i + 1 < tiles.length ? tiles[i + 1] : const SizedBox.shrink()),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _Tile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const _Tile({required this.icon, required this.title, required this.subtitle, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(AppRadius.lg);
    return PressableScale(
      child: Material(
        color: AppColors.surface,
        shape: RoundedRectangleBorder(borderRadius: radius, side: const BorderSide(color: AppColors.cardBorder)),
        child: InkWell(
          onTap: onTap,
          borderRadius: radius,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 132),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(color: AppColors.greenTonal, borderRadius: BorderRadius.circular(12)),
                    child: Icon(icon, size: 26, color: AppColors.green700),
                  ),
                  const SizedBox(height: 12),
                  Text(title, style: AppText.display(size: 16, weight: FontWeight.w600, height: 1.3)),
                  const SizedBox(height: 3),
                  Text(subtitle, style: AppText.detail()),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A date block (day over month), then the title and two lines of detail.
class _AnnouncementRow extends StatelessWidget {
  final DateTime? date;
  final String title;
  final String body;
  final String noDateLabel;
  final VoidCallback onTap;

  const _AnnouncementRow({
    required this.date,
    required this.title,
    required this.body,
    required this.noDateLabel,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final at = date?.toLocal();
    final month = at == null ? null : formatMonthShort(at);

    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Semantics(
              label: at == null ? noDateLabel : '$month ${at.day}',
              child: ExcludeSemantics(
                child: Container(
                  width: 52,
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.fieldFill,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Column(
                    children: [
                      Text(
                        at == null ? '—' : at.day.toString().padLeft(2, '0'),
                        style: AppText.display(size: 20, weight: FontWeight.w600, color: AppColors.sectionInk, height: 1.1)
                            .copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
                      ),
                      if (month != null)
                        Text(
                          month.toUpperCase(),
                          style: AppText.display(size: 12, weight: FontWeight.w600, color: AppColors.inkFaint, letterSpacing: .5),
                        ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: AppText.display(size: 16, weight: FontWeight.w600, height: 1.3)),
                  if (body.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(body, maxLines: 2, overflow: TextOverflow.ellipsis, style: AppText.detail()),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MutedLine extends StatelessWidget {
  final String text;

  const _MutedLine(this.text);

  @override
  Widget build(BuildContext context) => Text(text, style: AppText.body(size: 15, color: AppColors.inkMuted));
}
