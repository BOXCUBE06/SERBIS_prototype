import 'package:flutter/material.dart';
import '../data/hotlines.dart';
import '../models/request_models.dart';
import '../state/account_store.dart' show AppUser;
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/borrow_request_widgets.dart' show mdrrmoNumber;
import '../widgets/motion.dart';
import '../widgets/request_summary.dart';
import '../widgets/shared_widgets.dart';
import 'borrow_equipment_screen.dart';

/// Home's side margin, from the redesign (Home2 on the design canvas).
const double _gutter = 16;

/// The red-tinted border of the emergency bar; no theme token is this light.
const Color _emergencyBorder = Color(0xFFF0C9C3);

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

  /// Opens Borrow → My requests, for a loan's row. Left out, it opens Borrow
  /// the way [onOpenBorrow] does.
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
              if (active.isNotEmpty) ...[
                const SizedBox(height: 20),
                _RequestsSection(requests: active, filipino: f, onSeeAll: onOpenTrack, onOpenBorrow: onOpenMyLoans ?? () => _openBorrow(context)),
              ],
              const SizedBox(height: 20),
              _SectionTitle(tr(f, 'home.services')),
              const SizedBox(height: 10),
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
              Row(
                children: [
                  Expanded(child: _SectionTitle(tr(f, 'home.announcements'))),
                  _SeeAll(label: tr(f, 'home.see_all'), onTap: onOpenLibrary),
                ],
              ),
              const SizedBox(height: 6),
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

  /// The two most recent published materials, or one muted line about why
  /// there are none. The Library shows all of them.
  List<Widget> _announcements(bool f) {
    if (appState.materials.isEmpty) {
      if (appState.materialsLoading) return const [];
      return [_MutedLine(appState.materialsError == null ? tr(f, 'home.ann.empty') : tr(f, 'home.ann.failed'))];
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
            date: material.publishedAt == null
                ? tr(f, 'home.ann.no_date')
                : formatTimelineTime(material.publishedAt!, f),
            title: material.title,
            // Materials carry no body text; what they are is the next best line.
            body: [material.typeLabel, material.sizeLabel].where((part) => part.isNotEmpty).join(' · '),
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

  const _HomeHeader({
    required this.user,
    required this.filipino,
    required this.unread,
    required this.onNotificationsTap,
    required this.onProfileTap,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final white = Colors.white.withValues(alpha: .88);

    return Container(
      padding: const EdgeInsets.fromLTRB(_gutter, AppLayout.headerTop, 8, 22),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.white.withValues(alpha: .22)),
                ),
                child: const Icon(Icons.shield_outlined, color: Colors.white, size: 18),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  tr(f, 'home.brand'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppText.display(size: 13, weight: FontWeight.w600, color: Colors.white.withValues(alpha: .92), letterSpacing: 1.5),
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
          const SizedBox(height: 4),
          Padding(
            padding: const EdgeInsets.only(right: _gutter - 8),
            child: Text(user.accountName, style: AppText.display(size: 24, weight: FontWeight.w600, color: Colors.white)),
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
        ],
      ),
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
    return Material(
      color: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: _emergencyBorder),
      ),
      child: InkWell(
        onTap: onTap,
        customBorder: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 52),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            child: Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: const BoxDecoration(color: AppColors.red50, shape: BoxShape.circle),
                  child: const Icon(Icons.phone_outlined, size: 18, color: AppColors.red600),
                ),
                const SizedBox(width: 12),
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
                const Icon(Icons.chevron_right_rounded, color: AppColors.ink),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RequestsSection extends StatelessWidget {
  final List<RequestSummary> requests;
  final bool filipino;
  final VoidCallback onSeeAll;

  /// A loan row opens Borrow → My requests.
  final VoidCallback onOpenBorrow;

  const _RequestsSection({required this.requests, required this.filipino, required this.onSeeAll, required this.onOpenBorrow});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final more = requests.length - 2;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: _SectionTitle(tr(f, 'home.your_requests'))),
            _SeeAll(label: '${tr(f, 'home.see_all')} (${requests.length})', onTap: onSeeAll),
          ],
        ),
        const SizedBox(height: 6),
        SummaryCard(children: [
          for (final r in requests.take(2)) RequestSummaryRow(request: r, onTap: r.isBorrow ? onOpenBorrow : onSeeAll),
          if (more > 0)
            InkWell(
              onTap: onSeeAll,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 48),
                child: Center(
                  child: Text(
                    more == 1 ? tr(f, 'home.more_one') : tr(f, 'home.more_many').replaceAll('{n}', '$more'),
                    style: AppText.display(size: 15, weight: FontWeight.w500, color: AppColors.green700),
                  ),
                ),
              ),
            ),
        ]),
      ],
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
            padding: EdgeInsets.only(top: i == 0 ? 0 : 12),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: tiles[i]),
                  const SizedBox(width: 12),
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
    final radius = BorderRadius.circular(18);
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
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(color: AppColors.greenTonal, borderRadius: BorderRadius.circular(12)),
                    child: Icon(icon, size: 22, color: AppColors.green700),
                  ),
                  const SizedBox(height: 10),
                  Text(title, style: AppText.display(size: 16, weight: FontWeight.w600, height: 1.3)),
                  const SizedBox(height: 4),
                  Text(subtitle, style: AppText.body(size: 13.5, color: AppColors.inkMuted, height: 1.35)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _AnnouncementRow extends StatelessWidget {
  final String date;
  final String title;
  final String body;
  final VoidCallback onTap;

  const _AnnouncementRow({required this.date, required this.title, required this.body, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(date, style: AppText.body(size: 13, color: AppColors.inkMuted)),
            const SizedBox(height: 4),
            Text(title, style: AppText.display(size: 16, weight: FontWeight.w600, height: 1.35)),
            if (body.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                body,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: AppText.body(size: 15, color: AppColors.inkMuted, height: 1.45),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String text;

  const _SectionTitle(this.text);

  @override
  Widget build(BuildContext context) =>
      Text(text, style: AppText.display(size: 18, weight: FontWeight.w600, color: AppColors.sectionInk));
}

class _SeeAll extends StatelessWidget {
  final String label;
  final VoidCallback onTap;

  const _SeeAll({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onTap,
      style: TextButton.styleFrom(
        minimumSize: const Size(44, 44),
        foregroundColor: AppColors.green700,
        textStyle: AppText.display(size: 15, weight: FontWeight.w600),
      ),
      child: Text(label),
    );
  }
}

class _MutedLine extends StatelessWidget {
  final String text;

  const _MutedLine(this.text);

  @override
  Widget build(BuildContext context) => Text(text, style: AppText.body(size: 15, color: AppColors.inkMuted));
}
