library serbis.screens.borrow_equipment;

import 'dart:typed_data';

import 'package:flutter/material.dart';
import '../models/borrow_models.dart';
import '../models/request_models.dart' show dueLabel, formatTimelineTime;
import '../state/account_store.dart' show AppUser;
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/form_inputs.dart';
import '../widgets/form_section.dart';
import '../widgets/offline_banner.dart';
import '../widgets/shared_widgets.dart';

/// Browse the equipment MDRRMO lends out and file a loan request, or check the
/// status of ones already filed. A request that is still Pending or Approved
/// can be withdrawn from here — everything else on the record stays with
/// MDRRMO, since `borrowings` exposes `update`/`destroy` to `is.admin` only.
class BorrowEquipmentScreen extends StatefulWidget {
  final AppState appState;

  /// The signed-in resident. Only used to prefill the delivery address —
  /// everything else this screen and its sheet do reads from [appState].
  final AppUser user;

  /// True when this is the Borrow tab: it draws the collapsing green header in
  /// place of a back-button app bar, and follows the store so a loan MDRRMO
  /// approves shows up without leaving the tab.
  final bool embedded;
  final VoidCallback? onOpenNotifications;
  final VoidCallback? onOpenProfile;

  const BorrowEquipmentScreen({
    super.key,
    required this.appState,
    required this.user,
    this.embedded = false,
    this.onOpenNotifications,
    this.onOpenProfile,
  });

  @override
  State<BorrowEquipmentScreen> createState() => _BorrowEquipmentScreenState();
}

class _BorrowEquipmentScreenState extends State<BorrowEquipmentScreen> {
  bool _showMine = false;

  List<Equipment> _equipment = [];
  bool _loadingEquipment = true;
  String? _equipmentError;

  List<BorrowRequest> _myRequests = [];
  bool _loadingMine = true;

  final TextEditingController _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadEquipment();
    _loadMine();
    _search.addListener(() => setState(() {}));
    if (widget.embedded) {
      widget.appState.addListener(_syncMine);
    }
  }

  @override
  void dispose() {
    if (widget.embedded) {
      widget.appState.removeListener(_syncMine);
    }
    _search.dispose();
    super.dispose();
  }

  /// A tab outlives many visits, so the copy taken at mount goes stale. Only the
  /// list of the resident's own loans is re-read: the catalogue's load and error
  /// states are owned by [_loadEquipment].
  void _syncMine() {
    if (!mounted) return;
    setState(() => _myRequests = List.of(widget.appState.borrowRequests));
  }

  Future<void> _loadEquipment() async {
    await widget.appState.loadEquipment();
    if (!mounted) return;
    setState(() {
      // A copy, not the store's list — see ServicesScreen._loadServices for
      // why aliasing it is unsafe: a later reload mutates that list in place.
      _equipment = List.of(widget.appState.equipment);
      _equipmentError = widget.appState.equipmentError;
      _loadingEquipment = false;
    });
  }

  Future<void> _loadMine() async {
    await widget.appState.hydrateBorrowRequests();
    if (mounted) {
      setState(() => _myRequests = List.of(widget.appState.borrowRequests));
    }
    await widget.appState.loadBorrowRequests();
    if (!mounted) return;
    setState(() {
      _myRequests = List.of(widget.appState.borrowRequests);
      _loadingMine = false;
    });
  }

  int get _pendingCount => _myRequests.where((r) => r.status == BorrowStatus.pending).length;

  /// Anything not yet finished: pending, approved or out on loan.
  int get _inProgressCount => _myRequests.where((r) => !r.status.isTerminal).length;

  /// A null [item] opens the sheet for something the catalogue does not list,
  /// which is the only way `other_equipment_text` is ever sent.
  Future<void> _openBorrowSheet(Equipment? item) async {
    final filed = await showModalBottomSheet<BorrowRequest>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _BorrowSheet(
        filipino: widget.appState.language == AppLanguage.filipino,
        appState: widget.appState,
        user: widget.user,
        item: item,
      ),
    );

    // Focus returns to whatever opened the sheet, which kept its highlight lit.
    FocusManager.instance.primaryFocus?.unfocus();

    if (filed == null || !mounted) return;

    setState(() {
      _myRequests = List.of(widget.appState.borrowRequests);
      _showMine = true;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      // Reads back what the resident typed on the uncatalogued path, since
      // there is no catalogue name to confirm the request against.
      SnackBar(
        content: Text(trEn(widget.appState.language == AppLanguage.filipino,
                'Request filed for {item}. MDRRMO will review it.')
            .replaceAll('{item}', item?.name ?? filed.itemLabel)),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: widget.embedded
          ? null
          : AppBar(
              backgroundColor: AppColors.paper,
              elevation: 0,
              foregroundColor: AppColors.ink,
              title: Text(trEn(f, 'Borrow equipment'), style: AppText.display(size: AppTextSize.title)),
            ),
      body: RefreshIndicator(
        color: AppColors.green700,
        // Pull-to-refresh belongs to the resident's own requests only.
        notificationPredicate: (n) => _showMine && n.depth == 0,
        edgeOffset: widget.embedded ? _BorrowHeader.minHeight + _TabsHeader.height : _TabsHeader.height,
        onRefresh: _loadMine,
        child: CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            if (widget.embedded)
              SliverPersistentHeader(
                pinned: true,
                delegate: _BorrowHeader(
                  filipino: f,
                  onNotifications: widget.onOpenNotifications,
                  onProfile: widget.onOpenProfile,
                ),
              ),
            if (widget.appState.borrowIsOffline)
              SliverToBoxAdapter(
                child: OfflineBanner(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
              ),
            SliverPersistentHeader(
              pinned: true,
              delegate: _TabsHeader(
                availableLabel: trEn(f, 'Available'),
                mineLabel: trEn(f, 'My requests'),
                pending: _pendingCount,
                pendingLabel: trEn(f, '{n} pending').replaceAll('{n}', '$_pendingCount'),
                showMine: _showMine,
                onChanged: (mine) => setState(() => _showMine = mine),
              ),
            ),
            ...(_showMine ? _mineSlivers(f) : _availableSlivers(f)),
            const SliverToBoxAdapter(child: SizedBox(height: AppLayout.navClearance)),
          ],
        ),
      ),
    );
  }

  /// Side padding for everything under the tabs.
  Widget _pad(Widget child) => SliverPadding(
        padding: const EdgeInsets.symmetric(horizontal: AppLayout.gutter),
        sliver: child,
      );

  List<Widget> _availableSlivers(bool f) {
    if (_loadingEquipment) {
      return const [SliverFillRemaining(hasScrollBody: false, child: Center(child: CircularProgressIndicator()))];
    }

    if (_equipmentError != null && _equipment.isEmpty) {
      return [
        SliverFillRemaining(
          hasScrollBody: false,
          child: _ErrorState(
            filipino: f,
            message: _equipmentError!,
            onRetry: () {
              setState(() => _loadingEquipment = true);
              _loadEquipment();
            },
          ),
        ),
      ];
    }

    final other = _OtherEquipmentCard(filipino: f, onTap: () => _openBorrowSheet(null));

    // Reachable from the empty catalogue too: an office with nothing listed is
    // exactly when a resident has to name the item themselves.
    if (_equipment.isEmpty) {
      return [
        _pad(SliverList.list(children: [
          const SizedBox(height: AppSpacing.lg),
          _EmptyState(
            icon: Icons.inventory_2_outlined,
            title: trEn(f, 'Nothing available right now'),
            body: trEn(f, 'MDRRMO has no equipment listed for loan at the moment.'),
          ),
          const SizedBox(height: AppSpacing.md),
          other,
        ])),
      ];
    }

    final query = _search.text.trim().toLowerCase();
    final shown = query.isEmpty
        ? _equipment
        : _equipment.where((e) => e.name.toLowerCase().contains(query)).toList();
    final inProgress = _inProgressCount;

    return [
      _pad(SliverList.list(children: [
        const SizedBox(height: AppSpacing.md),
        if (inProgress > 0) ...[
          _InProgressBanner(
            text: inProgress == 1
                ? trEn(f, 'You have 1 request in progress')
                : trEn(f, 'You have {n} requests in progress').replaceAll('{n}', '$inProgress'),
            viewLabel: trEn(f, 'View'),
            onView: () => setState(() => _showMine = true),
          ),
          const SizedBox(height: AppSpacing.md),
        ],
        _SearchField(controller: _search, hint: trEn(f, 'Search equipment')),
        const SizedBox(height: AppSpacing.md),
        if (shown.isEmpty) ...[
          _EmptyState(
            icon: Icons.search_off_rounded,
            title: trEn(f, 'No equipment matches your search'),
            body: trEn(f, 'Try another name, or ask for it below.'),
          ),
          const SizedBox(height: AppSpacing.lg),
        ],
        for (final item in shown) ...[
          _EquipmentCard(filipino: f, item: item, onBorrow: () => _openBorrowSheet(item)),
          const SizedBox(height: AppSpacing.md),
        ],
        other,
      ])),
    ];
  }

  List<Widget> _mineSlivers(bool f) {
    if (_loadingMine && _myRequests.isEmpty) {
      return const [SliverFillRemaining(hasScrollBody: false, child: Center(child: CircularProgressIndicator()))];
    }

    if (_myRequests.isEmpty) {
      return [
        SliverFillRemaining(
          hasScrollBody: false,
          child: _EmptyState(
            icon: Icons.assignment_outlined,
            title: trEn(f, 'No borrow requests yet'),
            body: trEn(f, 'Items you request from the Available tab will show up here.'),
          ),
        ),
      ];
    }

    return [
      _pad(SliverList.list(children: [
        const SizedBox(height: AppSpacing.lg),
        if (widget.appState.borrowRequestsFromCache)
          StaleDataNote(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
        for (final r in _myRequests)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.md),
            child: _BorrowRequestCard(
              request: r,
              filipino: f,
              onCancel: () => widget.appState.cancelBorrowRequest(r.id),
              loadPhoto: (stage) => widget.appState.borrowPhoto(r.id!, stage),
            ),
          ),
      ])),
    ];
  }
}

/// Green header: "Borrow equipment" over its subtitle, bell and profile on the
/// right. Shrinks to a one-line bar as the list scrolls.
class _BorrowHeader extends SliverPersistentHeaderDelegate {
  static const maxHeight = 128.0;
  static const minHeight = 96.0;

  final bool filipino;
  final VoidCallback? onNotifications;
  final VoidCallback? onProfile;

  const _BorrowHeader({required this.filipino, this.onNotifications, this.onProfile});

  @override
  double get maxExtent => maxHeight;

  @override
  double get minExtent => minHeight;

  @override
  bool shouldRebuild(_BorrowHeader old) => old.filipino != filipino;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    final f = filipino;
    // 0 fully open, 1 fully collapsed.
    final t = (shrinkOffset / (maxHeight - minHeight)).clamp(0.0, 1.0);
    return Container(
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, AppLayout.headerTop, 14, 20 - 12 * t),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.xxl)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    trEn(f, 'Borrow equipment'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.display(
                      size: AppTextSize.headline - (AppTextSize.headline - AppTextSize.title) * t,
                      color: Colors.white,
                    ),
                  ),
                ),
                ClipRect(
                  child: Align(
                    alignment: Alignment.topLeft,
                    heightFactor: 1 - t,
                    child: Opacity(
                      opacity: 1 - t,
                      child: Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(
                          trEn(f, 'Free loans from Echague MDRRMO'),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .85)),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (onNotifications != null)
            HeaderButton(icon: Icons.notifications_outlined, label: tr(f, 'nav.notifications'), onTap: onNotifications!),
          if (onProfile != null)
            HeaderButton(icon: Icons.person_outline_rounded, label: tr(f, 'nav.profile'), onTap: onProfile!),
        ],
      ),
    );
  }
}

/// The Available / My requests tabs, pinned under the header.
class _TabsHeader extends SliverPersistentHeaderDelegate {
  static const height = 64.0;

  final String availableLabel;
  final String mineLabel;
  final int pending;
  final String pendingLabel;
  final bool showMine;
  final ValueChanged<bool> onChanged;

  const _TabsHeader({
    required this.availableLabel,
    required this.mineLabel,
    required this.pending,
    required this.pendingLabel,
    required this.showMine,
    required this.onChanged,
  });

  @override
  double get maxExtent => height;

  @override
  double get minExtent => height;

  @override
  bool shouldRebuild(_TabsHeader old) =>
      old.showMine != showMine || old.pending != pending || old.mineLabel != mineLabel;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return Container(
      color: AppColors.paper,
      padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 4),
      child: Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(AppRadius.pill)),
        child: Row(
          children: [
            Expanded(child: _tab(availableLabel, !showMine, () => onChanged(false))),
            Expanded(
              child: _tab(
                mineLabel,
                showMine,
                () => onChanged(true),
                badge: pending > 0 ? _CountBadge(count: pending, label: pendingLabel) : null,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _tab(String label, bool active, VoidCallback onTap, {Widget? badge}) {
    return Semantics(
      selected: active,
      button: true,
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          height: 44,
          decoration: BoxDecoration(
            color: active ? AppColors.surface : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.pill),
            boxShadow: active
                ? [BoxShadow(color: AppColors.green900.withValues(alpha: .06), blurRadius: 8, offset: const Offset(0, 2))]
                : null,
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Flexible(
                child: Text(
                  label,
                  overflow: TextOverflow.ellipsis,
                  style: AppText.display(
                    size: AppTextSize.body,
                    weight: FontWeight.w600,
                    color: active ? AppColors.ink : AppColors.inkMuted,
                  ),
                ),
              ),
              if (badge != null) ...[const SizedBox(width: 6), badge],
            ],
          ),
        ),
      ),
    );
  }
}

/// Round primary-coloured count, e.g. pending requests.
class _CountBadge extends StatelessWidget {
  final int count;

  /// What the number means, for screen readers ("2 pending").
  final String label;

  const _CountBadge({required this.count, required this.label});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: label,
      excludeSemantics: true,
      child: Container(
        constraints: const BoxConstraints(minWidth: 20),
        height: 20,
        padding: const EdgeInsets.symmetric(horizontal: 6),
        alignment: Alignment.center,
        decoration: BoxDecoration(color: AppColors.green700, borderRadius: BorderRadius.circular(AppRadius.pill)),
        child: Text(
          '$count',
          style: AppText.display(size: AppTextSize.caption, weight: FontWeight.w700, color: Colors.white),
        ),
      ),
    );
  }
}

/// "You have N requests in progress — View", above the catalogue.
class _InProgressBanner extends StatelessWidget {
  final String text;
  final String viewLabel;
  final VoidCallback onView;

  const _InProgressBanner({required this.text, required this.viewLabel, required this.onView});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.greenNotice,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        side: const BorderSide(color: AppColors.greenNoticeBorder),
      ),
      child: InkWell(
        onTap: onView,
        canRequestFocus: false,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Row(
              children: [
                const Icon(Icons.schedule_rounded, size: 18, color: AppColors.green700),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(text, style: AppText.body(size: AppTextSize.body, weight: FontWeight.w500)),
                ),
                const SizedBox(width: 8),
                Text(
                  viewLabel,
                  style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.green700),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// 48dp search box; filtering happens in the screen.
class _SearchField extends StatelessWidget {
  final TextEditingController controller;
  final String hint;

  const _SearchField({required this.controller, required this.hint});

  @override
  Widget build(BuildContext context) {
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: const BorderSide(color: AppColors.fieldBorder),
    );
    return TextField(
      controller: controller,
      textInputAction: TextInputAction.search,
      style: AppText.body(size: AppTextSize.bodyLg),
      decoration: InputDecoration(
        hintText: hint,
        hintStyle: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkFaint),
        prefixIcon: const Icon(Icons.search_rounded, color: AppColors.inkMuted),
        filled: true,
        fillColor: AppColors.fieldFill,
        constraints: const BoxConstraints(minHeight: 48),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: const BorderSide(color: AppColors.green600, width: 1.5)),
      ),
    );
  }
}

/// One catalogue item: name and stock on the left, a tonal Borrow button on
/// the right. The whole card opens the borrow sheet.
class _EquipmentCard extends StatelessWidget {
  final Equipment item;
  final VoidCallback onBorrow;
  final bool filipino;

  const _EquipmentCard({required this.item, required this.filipino, required this.onBorrow});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final stock = item.availableQuantity;
    final out = stock <= 0;
    final low = stock == 1;

    final (Color dot, Color textColor, String stockText) = out
        ? (AppColors.inkFaint, AppColors.inkMuted, trEn(f, 'Unavailable'))
        : low
            ? (AppColors.amberDot, AppColors.amberInk, trEn(f, 'Only 1 left'))
            : (AppColors.green600, AppColors.inkMuted, trEn(f, '{n} available').replaceAll('{n}', '$stock'));

    return Opacity(
      opacity: out ? .55 : 1,
      child: Material(
        color: AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.card),
          side: const BorderSide(color: AppColors.line),
        ),
        child: InkWell(
          onTap: out ? null : onBorrow,
          canRequestFocus: false,
          borderRadius: BorderRadius.circular(AppRadius.card),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 14, 16),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(item.name, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          Container(width: 7, height: 7, decoration: BoxDecoration(color: dot, shape: BoxShape.circle)),
                          const SizedBox(width: 6),
                          Flexible(
                            child: Text(stockText, style: AppText.body(size: AppTextSize.small, color: textColor)),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                SizedBox(
                  height: 36,
                  child: TextButton(
                    onPressed: out ? null : onBorrow,
                    style: TextButton.styleFrom(
                      backgroundColor: AppColors.greenTonal,
                      foregroundColor: AppColors.green700,
                      disabledBackgroundColor: AppColors.grey50,
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      // 36dp drawn; the whole card is the bigger target.
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.sm)),
                      textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
                    ),
                    child: Text(trEn(f, 'Borrow')),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The way into an uncatalogued request. Its own card rather than a field in
/// the sheet: opening the sheet without an item is what makes sending both an
/// `equipment_id` and an `other_equipment_text` unrepresentable.
class _OtherEquipmentCard extends StatelessWidget {
  final VoidCallback onTap;
  final bool filipino;

  const _OtherEquipmentCard({required this.onTap, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final radius = BorderRadius.circular(AppRadius.card);
    return CustomPaint(
      foregroundPainter: const _DashedBorder(radius: AppRadius.card),
      child: Material(
        color: Colors.transparent,
        borderRadius: radius,
        child: InkWell(
          onTap: onTap,
          canRequestFocus: false,
          borderRadius: radius,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 14, 16),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(color: AppColors.sand, borderRadius: BorderRadius.circular(AppRadius.sm)),
                  child: const Icon(Icons.add_rounded, color: AppColors.inkMuted),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(trEn(f, 'Need something else?'),
                          style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                      const SizedBox(height: 2),
                      Text(
                        trEn(f, "Ask for an item that's not on this list"),
                        style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// 1.5px dashed rounded border; Flutter has no dashed BorderSide.
class _DashedBorder extends CustomPainter {
  final double radius;

  const _DashedBorder({required this.radius});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = AppColors.dashed
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    final path = Path()
      ..addRRect(RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius)).deflate(.75));
    for (final metric in path.computeMetrics()) {
      for (var d = 0.0; d < metric.length; d += 9) {
        canvas.drawPath(metric.extractPath(d, d + 5), paint);
      }
    }
  }

  @override
  bool shouldRepaint(_DashedBorder old) => old.radius != radius;
}

class _BorrowRequestCard extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;

  /// Resolves to `true` only once MDRRMO has accepted the withdrawal — the
  /// dialog waits for it before saying anything happened.
  final Future<bool> Function() onCancel;

  /// Bytes for one handover stage, or null when the fetch found nothing. Only
  /// called for a stage the row says exists.
  final Future<List<int>?> Function(String stage) loadPhoto;

  const _BorrowRequestCard({
    required this.request,
    required this.filipino,
    required this.onCancel,
    required this.loadPhoto,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      leftAccent: request.status.fg,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${request.itemLabel} × ${request.quantity}',
                      style: AppText.display(size: AppTextSize.bodyLg),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      request.id == null
                          ? trEn(filipino, 'Sending...')
                          : request.createdAt == null
                              ? trEn(filipino, 'Filed')
                              : trEn(filipino, 'Filed {time}')
                                  .replaceAll('{time}', formatTimelineTime(request.createdAt!, filipino)),
                      style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                    ),
                  ],
                ),
              ),
              StatusBadge.borrow(request.status, filipino: filipino),
            ],
          ),
          // Rows filed before `purpose` existed have none, and the card says
          // nothing rather than showing an empty quote.
          if (request.purpose != null && request.purpose!.isNotEmpty) ...[
            const SizedBox(height: 10),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.notes_rounded, size: 14, color: AppColors.inkFaint),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    request.purpose!,
                    style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.45),
                  ),
                ),
              ],
            ),
          ],
          if (request.status == BorrowStatus.denied && request.denialReason != null) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(11),
              decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(AppRadius.sm)),
              child: Text(request.denialReason!, style: AppText.body(size: AppTextSize.small, color: AppColors.red600, height: 1.5)),
            ),
          ],
          if (request.dueDate != null && !request.status.isTerminal) ...[
            const SizedBox(height: 10),
            Builder(builder: (context) {
              // Prominent, not a small caption line — MDRRMO feedback,
              // 2026-09-17. Same three-tier colouring the admin panel's own
              // countdown chip uses: red once overdue, amber inside the
              // 1-day reminder window, neutral otherwise.
              final label = dueLabel(request.dueDate!, null, filipino);
              // Classified off the English label, which does not change with the language.
              final english = dueLabel(request.dueDate!);
              final overdue = english.endsWith('overdue');
              final urgent = english == 'Due today' || english == 'Due tomorrow';
              final bg = overdue ? AppColors.red50 : (urgent ? AppColors.amber50 : AppColors.grey50);
              final fg = overdue ? AppColors.red600 : (urgent ? AppColors.amber600 : AppColors.inkMuted);
              return Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
                decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.sm)),
                child: Row(
                  children: [
                    Icon(Icons.event_outlined, size: 16, color: fg),
                    const SizedBox(width: 8),
                    Text(
                      label,
                      style: AppText.body(size: AppTextSize.body, weight: FontWeight.w700, color: fg),
                    ),
                  ],
                ),
              );
            }),
          ],
          // Staff photograph the item at the counter; the resident only reads
          // it back. A row with neither photo draws nothing at all — most
          // loans have none, and an empty "Handover photos" heading would read
          // as something missing rather than something never taken.
          if (request.id != null &&
              (request.hasReleasePhoto || request.hasReturnPhoto)) ...[
            const SizedBox(height: 12),
            _HandoverPhotos(
              filipino: filipino,
              hasRelease: request.hasReleasePhoto,
              hasReturn: request.hasReturnPhoto,
              loadPhoto: loadPhoto,
            ),
          ],
          // An id-less row is still in flight, so MDRRMO has nothing to cancel
          // yet — the button waits rather than offering an action the store
          // would have to refuse. The backend re-checks the status either way.
          if (request.id != null && request.status.isCancellable) ...[
            const SizedBox(height: 12),
            AppButton(
              label: tr(filipino, 'common.cancel_request'),
              style: AppButtonStyle.ghostRed,
              // No ref number on a borrowing — the dialog drops the suffix and
              // reads "Cancel request?" on its own.
              onPressed: () => showCancelDialog(context, '', onCancel, filipino: filipino),
            ),
          ],
        ],
      ),
    );
  }
}

/// The photographs staff took when the item changed hands. Display only —
/// POST /borrowings/{id}/photo is behind `is.admin`, and this app has no way
/// to add or replace one.
class _HandoverPhotos extends StatelessWidget {
  final bool hasRelease;
  final bool hasReturn;
  final Future<List<int>?> Function(String stage) loadPhoto;
  final bool filipino;

  const _HandoverPhotos({
    required this.filipino,
    required this.hasRelease,
    required this.hasReturn,
    required this.loadPhoto,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          trEn(filipino, 'Handover photos'),
          style: AppText.display(size: AppTextSize.small, color: AppColors.inkMuted),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            if (hasRelease)
              _HandoverThumbnail(
                label: trEn(filipino, 'Released'),
                stage: 'release',
                loadPhoto: loadPhoto,
              ),
            if (hasRelease && hasReturn) const SizedBox(width: 10),
            if (hasReturn)
              _HandoverThumbnail(
                label: trEn(filipino, 'Returned'),
                stage: 'return',
                loadPhoto: loadPhoto,
              ),
          ],
        ),
      ],
    );
  }
}

/// One stage's photograph. Fetched once when the card is built and held here:
/// the bytes are heavier than the borrow list itself, so they are never cached
/// to disk and never kept on the row.
class _HandoverThumbnail extends StatefulWidget {
  final String label;
  final String stage;
  final Future<List<int>?> Function(String stage) loadPhoto;

  const _HandoverThumbnail({
    required this.label,
    required this.stage,
    required this.loadPhoto,
  });

  @override
  State<_HandoverThumbnail> createState() => _HandoverThumbnailState();
}

class _HandoverThumbnailState extends State<_HandoverThumbnail> {
  Uint8List? _bytes;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // fetchHandoverPhoto swallows its own failures and answers null, so a
    // photo the server cannot serve leaves the tile in its "not available"
    // state instead of throwing inside a list item.
    final bytes = await widget.loadPhoto(widget.stage);
    if (!mounted) return;
    setState(() {
      _bytes = bytes == null ? null : Uint8List.fromList(bytes);
      _loading = false;
    });
  }

  void _openFullSize() {
    final bytes = _bytes;
    if (bytes == null) return;

    showDialog<void>(
      context: context,
      builder: (dialogContext) => Dialog(
        backgroundColor: Colors.black,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          children: [
            InteractiveViewer(
              child: Center(child: Image.memory(bytes, fit: BoxFit.contain)),
            ),
            Positioned(
              top: 4,
              right: 4,
              child: IconButton(
                icon: const Icon(Icons.close_rounded, color: Colors.white),
                onPressed: () => Navigator.of(dialogContext).pop(),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          onTap: _openFullSize,
          child: Container(
            width: 76,
            height: 76,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              color: AppColors.grey50,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: _tile(),
          ),
        ),
        const SizedBox(height: 5),
        Text(
          widget.label,
          style: AppText.body(size: AppTextSize.caption, color: AppColors.inkMuted),
        ),
      ],
    );
  }

  Widget _tile() {
    if (_loading) {
      return const Center(
        child: SizedBox(
          width: 18,
          height: 18,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      );
    }

    final bytes = _bytes;
    if (bytes == null) {
      return const Center(
        child: Icon(Icons.image_not_supported_outlined,
            size: 20, color: AppColors.inkFaint),
      );
    }

    return Image.memory(bytes, fit: BoxFit.cover, width: 76, height: 76);
  }
}

class _EmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;

  const _EmptyState({required this.icon, required this.title, required this.body});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: AppColors.inkFaint),
            const SizedBox(height: 12),
            Text(title, style: AppText.display(size: AppTextSize.bodyLg), textAlign: TextAlign.center),
            const SizedBox(height: 6),
            Text(body, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5), textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  final bool filipino;

  const _ErrorState({required this.message, required this.onRetry, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.wifi_off_rounded, size: 40, color: AppColors.inkFaint),
            const SizedBox(height: 12),
            Text(message, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted), textAlign: TextAlign.center),
            const SizedBox(height: 12),
            TextButton(onPressed: onRetry, child: Text(trEn(filipino, 'Retry'))),
          ],
        ),
      ),
    );
  }
}

/// The caption over a toggle. `AppTextField` draws its own label; these two
/// pickers have none, and unlabelled pills read as a filter rather than a
/// question being asked.
class _FieldLabel extends StatelessWidget {
  final String text;

  const _FieldLabel(this.text);

  @override
  Widget build(BuildContext context) {
    return Text(text, style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600));
  }
}

/// Quantity picker + submit. A separate widget rather than a method on the
/// screen because it needs its own `setState` for the stepper and the
/// in-flight spinner without rebuilding the whole screen behind it.
class _BorrowSheet extends StatefulWidget {
  final AppState appState;
  final AppUser user;

  /// Null for an item the catalogue does not list, which is when the sheet
  /// asks for its name instead of showing one.
  final Equipment? item;
  final bool filipino;

  const _BorrowSheet({
    required this.filipino,
    required this.appState,
    required this.user,
    required this.item,
  });

  @override
  State<_BorrowSheet> createState() => _BorrowSheetState();
}

class _BorrowSheetState extends State<_BorrowSheet> {
  /// Matches `purpose`'s column width. The server rejects anything longer, and
  /// finding that out only after a round trip loses what was typed past 255.
  static const _purposeMaxLength = 255;

  /// `other_equipment_text` and `delivery_address` are both varchar(255). Same
  /// reasoning as above.
  static const _otherItemMaxLength = 255;
  static const _addressMaxLength = 255;

  /// Only bounds the uncatalogued path, where there is no stock figure to stop
  /// at. The stepper is one tap per unit, so it needs an end somewhere.
  static const _uncataloguedMaxQuantity = 99;

  late int _quantity = _maxQuantity > 0 ? 1 : 0;
  final TextEditingController _purpose = TextEditingController();
  final TextEditingController _otherItem = TextEditingController();
  final TextEditingController _address = TextEditingController();

  bool _delivery = false;

  bool _submitting = false;
  String? _error;
  String? _purposeError;
  String? _otherItemError;
  String? _addressError;

  bool get _uncatalogued => widget.item == null;

  String _t(String english) => trEn(widget.filipino, english);

  int get _maxQuantity => widget.item?.availableQuantity ?? _uncataloguedMaxQuantity;

  @override
  void initState() {
    super.initState();
    // Clears the "tell MDRRMO..." error as soon as there is something to send,
    // rather than leaving a red field under text that would now be accepted.
    _purpose.addListener(() => _clearIfFilled(_purpose, _purposeError, () => _purposeError = null));
    _otherItem.addListener(() => _clearIfFilled(_otherItem, _otherItemError, () => _otherItemError = null));
    _address.addListener(() => _clearIfFilled(_address, _addressError, () => _addressError = null));
    // The Send button's enabled state follows the fields.
    for (final field in [_purpose, _otherItem, _address]) {
      field.addListener(_refresh);
    }
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  void _clearIfFilled(TextEditingController field, String? error, VoidCallback clear) {
    if (error != null && field.text.trim().isNotEmpty) setState(clear);
  }

  @override
  void dispose() {
    _purpose.dispose();
    _otherItem.dispose();
    _address.dispose();
    super.dispose();
  }

  void _step(int delta) {
    final next = _quantity + delta;
    if (next < 1 || next > _maxQuantity) return;
    setState(() => _quantity = next);
  }

  Future<void> _confirm() async {
    if (_submitting || _quantity < 1) return;

    // Checked here as well as on the server: each of these is `required` or
    // `required_if` on `POST /borrowings`, and a round trip to be told a box is
    // empty is a worse way to learn it than the field going red.
    final purpose = _purpose.text.trim();
    final otherItem = _otherItem.text.trim();
    final address = _address.text.trim();

    final missingItem = _uncatalogued && otherItem.isEmpty;
    final missingAddress = _delivery && address.isEmpty;

    if (purpose.isEmpty || missingItem || missingAddress) {
      setState(() {
        _purposeError = purpose.isEmpty ? _t('Tell MDRRMO what you need this for.') : null;
        _otherItemError = missingItem ? _t('Name the item you need.') : null;
        _addressError = missingAddress ? _t('Where should MDRRMO deliver it?') : null;
      });
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    final result = await widget.appState.submitBorrowRequest(
      item: widget.item,
      otherEquipmentText: _uncatalogued ? otherItem : null,
      quantity: _quantity,
      purpose: purpose,
      fulfillmentMethod: _delivery ? 'Delivery' : 'Pickup',
      // Sent only with the method it belongs to. A resident who types an
      // address and then switches back to Pickup must not still be delivered to.
      deliveryAddress: _delivery ? address : null,
    );

    if (!mounted) return;

    if (result == null) {
      setState(() {
        _submitting = false;
        _error = widget.appState.takeError() ?? _t('Something went wrong. Please try again.');
      });
      return;
    }

    Navigator.pop(context, result);
  }

  /// Everything the server requires is filled in. Drives the Send button.
  bool get _valid =>
      _quantity >= 1 &&
      _purpose.text.trim().isNotEmpty &&
      (!_uncatalogued || _otherItem.text.trim().isNotEmpty) &&
      (!_delivery || _address.text.trim().isNotEmpty);

  void _setDelivery(bool delivery) => setState(() {
        _delivery = delivery;
        // Prefilled with the saved address; still editable.
        if (delivery && _address.text.trim().isEmpty) _address.text = widget.user.fullAddress;
        // The field is about to disappear; leaving its error behind would block
        // a submit with nothing on screen to explain it.
        if (!delivery) _addressError = null;
      });

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: Container(
        constraints: BoxConstraints(maxHeight: media.size.height * .9),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(),
            // Fields scroll; the header and the Send button stay put.
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 8, AppLayout.gutter, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: _fields(),
                ),
              ),
            ),
            _footer(media.padding.bottom),
          ],
        ),
      ),
    );
  }

  Widget _header() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 18, 12, 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _uncatalogued ? _t('Request another item') : widget.item!.name,
                  style: AppText.display(size: AppTextSize.title),
                ),
                const SizedBox(height: 2),
                Text(
                  _uncatalogued
                      ? _t('For equipment not on the list')
                      : _t('{n} available to borrow').replaceAll('{n}', '${widget.item!.availableQuantity}'),
                  style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
                ),
              ],
            ),
          ),
          IconButton(
            onPressed: () => Navigator.pop(context),
            tooltip: _t('Close'),
            icon: const Icon(Icons.close_rounded, size: 20),
            style: IconButton.styleFrom(
              backgroundColor: AppColors.grey50,
              foregroundColor: AppColors.ink,
              fixedSize: const Size(44, 44),
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _fields() {
    return [
      if (_uncatalogued) ...[
        AppTextField(
          label: _t('What do you need?'),
          hint: _t('e.g. Portable generator'),
          controller: _otherItem,
          maxLength: _otherItemMaxLength,
          errorText: _otherItemError,
          enabled: !_submitting,
          isRequired: true,
        ),
        const SizedBox(height: AppSpacing.sm),
      ],
      Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_t('Quantity'), style: AppText.fieldLabel()),
                Text(_t('How many you need'), style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
              ],
            ),
          ),
          _stepButton(Icons.remove_rounded, _t('Decrease quantity'), _quantity > 1 ? () => _step(-1) : null),
          SizedBox(
            width: 44,
            child: Text('$_quantity', textAlign: TextAlign.center, style: AppText.display(size: AppTextSize.title)),
          ),
          _stepButton(Icons.add_rounded, _t('Increase quantity'), _quantity < _maxQuantity ? () => _step(1) : null),
        ],
      ),
      const SizedBox(height: AppSpacing.lg),
      AppTextField(
        label: _t('What will you use it for?'),
        hint: _t('e.g. Barangay flood drill this weekend'),
        controller: _purpose,
        lines: 3,
        maxLength: _purposeMaxLength,
        errorText: _purposeError,
        enabled: !_submitting,
        isRequired: true,
      ),
      const SizedBox(height: AppSpacing.sm),
      _FieldLabel(_t('How will you get it?')),
      const SizedBox(height: AppSpacing.sm),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: OptionCard(
              icon: Icons.storefront_outlined,
              title: _t('Pickup'),
              hint: _t('At MDRRMO office'),
              selected: !_delivery,
              onTap: _submitting ? null : () => _setDelivery(false),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: OptionCard(
              icon: Icons.local_shipping_outlined,
              title: _t('Delivery'),
              hint: _t('To your address'),
              selected: _delivery,
              onTap: _submitting ? null : () => _setDelivery(true),
            ),
          ),
        ],
      ),
      if (_delivery) ...[
        const SizedBox(height: AppSpacing.md),
        AppTextField(
          label: _t('Deliver to'),
          hint: _t('House number, street, barangay'),
          controller: _address,
          lines: 2,
          maxLength: _addressMaxLength,
          errorText: _addressError,
          enabled: !_submitting,
          isRequired: true,
        ),
      ],
      if (_error != null) ...[
        const SizedBox(height: AppSpacing.md),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(11),
          decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(AppRadius.sm)),
          child: Text(_error!, style: AppText.body(size: AppTextSize.small, color: AppColors.red600, height: 1.5)),
        ),
      ],
    ];
  }

  Widget _footer(double bottomInset) {
    return Container(
      padding: EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 16 + bottomInset),
      decoration: const BoxDecoration(border: Border(top: BorderSide(color: AppColors.line))),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.info_outline_rounded, size: 16, color: AppColors.inkMuted),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _t('MDRRMO will check if they can lend this and notify you.'),
                  style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.4),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          // Looks disabled until valid; a tap anyway still runs the checks so
          // the resident is told which field is missing.
          GestureDetector(
            onTap: _valid ? null : _confirm,
            child: SizedBox(
              height: 50,
              child: AppButton(
                label: _t('Send request'),
                onPressed: _valid ? _confirm : null,
                loading: _submitting,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _stepButton(IconData icon, String label, VoidCallback? onTap) {
    return IconButton(
      onPressed: _submitting ? null : onTap,
      tooltip: label,
      icon: Icon(icon, size: 20),
      style: IconButton.styleFrom(
        fixedSize: const Size(44, 44),
        foregroundColor: AppColors.ink,
        disabledForegroundColor: AppColors.inkFaint,
        side: const BorderSide(color: AppColors.fieldBorder),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
      ),
    );
  }
}
