library serbis.screens.borrow_equipment;

import 'dart:typed_data';

import 'package:flutter/material.dart';
import '../data/hotlines.dart' show callHotlineNumber;
import '../models/borrow_models.dart';
import '../models/request_models.dart' show formatMonthShort, formatStepTime;
import '../state/account_store.dart' show AppUser;
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/form_inputs.dart';
import '../widgets/borrow_request_widgets.dart';
import '../widgets/feedback.dart';
import '../widgets/form_section.dart';
import '../widgets/loading.dart';
import '../widgets/offline_banner.dart';
import '../widgets/request_summary.dart' show SummaryCard;
import '../widgets/shared_widgets.dart';
import '../widgets/status_line.dart';

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
  State<BorrowEquipmentScreen> createState() => BorrowEquipmentScreenState();
}

class BorrowEquipmentScreenState extends State<BorrowEquipmentScreen> {
  bool _showMine = false;

  /// Switches to My requests. Home's and Track's loan rows land here.
  void showMyRequests() => setState(() => _showMine = true);

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
    final mine = _myRequests.length;

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: RefreshIndicator(
        color: AppColors.green700,
        // Pull-to-refresh belongs to the resident's own requests only.
        notificationPredicate: (n) => _showMine && n.depth == 0,
        edgeOffset: TabHeader.minHeight + TabHeader.bottomHeight,
        onRefresh: _loadMine,
        child: CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverPersistentHeader(
              pinned: true,
              delegate: TabHeader(
                title: trEn(f, 'Borrow equipment'),
                subtitle: trEn(f, 'Free loans from Echague MDRRMO'),
                filipino: f,
                // Pushed from Home on a host with no Borrow tab: a back arrow
                // instead of the bell and profile.
                onBack: widget.embedded ? null : () => Navigator.of(context).maybePop(),
                onNotifications: widget.embedded ? widget.onOpenNotifications : null,
                onProfile: widget.embedded ? widget.onOpenProfile : null,
                bottom: _Segmented(
                  labels: [
                    trEn(f, 'Available'),
                    mine == 0 ? trEn(f, 'My requests') : trEn(f, 'My requests ({n})').replaceAll('{n}', '$mine'),
                  ],
                  selected: _showMine ? 1 : 0,
                  onChanged: (i) => setState(() => _showMine = i == 1),
                ),
              ),
            ),
            if (widget.appState.borrowIsOffline)
              SliverToBoxAdapter(
                child: OfflineBanner(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
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

  /// The list starts 16 under the header.
  Widget _list(List<Widget> children) =>
      _pad(SliverList.list(children: [const SizedBox(height: AppSpacing.lg), ...children]));

  List<Widget> _availableSlivers(bool f) {
    if (_loadingEquipment) {
      return [_list([SkeletonRows(count: 5, filipino: f)])];
    }

    if (_equipmentError != null && _equipment.isEmpty) {
      return [
        _list([
          LoadErrorBox(
            title: trEn(f, "Couldn't load equipment"),
            body: _equipmentError!,
            filipino: f,
            onRetry: () {
              setState(() => _loadingEquipment = true);
              _loadEquipment();
            },
          ),
        ]),
      ];
    }

    final other = SummaryCard(children: [_OtherEquipmentRow(filipino: f, onTap: () => _openBorrowSheet(null))]);

    // Reachable from the empty catalogue too: an office with nothing listed is
    // exactly when a resident has to name the item themselves.
    if (_equipment.isEmpty) {
      return [
        _list([
          EmptyState(
            title: trEn(f, 'Nothing available right now'),
            body: trEn(f, 'MDRRMO has no equipment listed for loan at the moment.'),
          ),
          const SizedBox(height: 14),
          other,
        ]),
      ];
    }

    final query = _search.text.trim().toLowerCase();
    final shown = query.isEmpty
        ? _equipment
        : _equipment.where((e) => e.name.toLowerCase().contains(query)).toList();

    return [
      _list([
        _SearchField(controller: _search, hint: trEn(f, 'Search equipment')),
        const SizedBox(height: 14),
        if (shown.isEmpty)
          EmptyState(
            title: trEn(f, 'No equipment matches your search'),
            body: trEn(f, 'Try another name, or ask for it below.'),
          )
        else
          SummaryCard(children: [
            for (final item in shown) _EquipmentRow(filipino: f, item: item, onBorrow: () => _openBorrowSheet(item)),
          ]),
        const SizedBox(height: 14),
        other,
      ]),
    ];
  }

  List<Widget> _mineSlivers(bool f) {
    if (_loadingMine && _myRequests.isEmpty) {
      return [_list([SkeletonCard(filipino: f)])];
    }

    if (_myRequests.isEmpty) {
      return [
        _list([
          EmptyState(
            title: trEn(f, 'No borrow requests yet'),
            body: trEn(f, 'Items you request from the Available tab will show up here.'),
            actionLabel: trEn(f, 'See available equipment'),
            onAction: () => setState(() => _showMine = false),
          ),
        ]),
      ];
    }

    final active = _myRequests.where((r) => !r.status.isTerminal).toList();
    final past = _myRequests.where((r) => r.status.isTerminal).toList();
    final phone = mdrrmoNumber(widget.appState.hotlines);

    Future<List<int>?> loadPhoto(BorrowRequest r, String stage) => widget.appState.borrowPhoto(r.id!, stage);

    return [
      _list([
        if (widget.appState.borrowRequestsFromCache) ...[
          StaleDataNote(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
          const SizedBox(height: AppSpacing.md),
        ],
        if (active.isNotEmpty) ...[
          SectionHeader(title: trEn(f, 'In progress'), count: active.length),
          for (final r in active)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.md),
              child: _BorrowRequestCard(
                request: r,
                filipino: f,
                onCancel: () => widget.appState.cancelBorrowRequest(r.id),
                loadPhoto: (stage) => loadPhoto(r, stage),
                onCall: phone == null ? null : () => callHotlineNumber(phone),
              ),
            ),
        ],
        if (past.isNotEmpty) ...[
          if (active.isNotEmpty) const SizedBox(height: AppSpacing.md),
          SectionHeader(title: trEn(f, 'Past requests')),
          SummaryCard(children: [
            for (final r in past)
              _PastLoanRow(
                request: r,
                filipino: f,
                loadPhoto: (stage) => loadPhoto(r, stage),
                onBorrowAgain: () {
                  // Borrow again only while the item is still in the catalogue.
                  final item = _equipment.where((e) => e.id == r.equipmentId).firstOrNull;
                  return item == null ? null : () => _openBorrowSheet(item);
                }(),
              ),
          ]),
        ],
      ]),
    ];
  }
}

/// Two choices on the green header, the chosen one on white.
class _Segmented extends StatelessWidget {
  final List<String> labels;
  final int selected;
  final ValueChanged<int> onChanged;

  const _Segmented({required this.labels, required this.selected, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(AppRadius.md)),
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++) ...[
            if (i > 0) const SizedBox(width: 4),
            Expanded(
              child: Semantics(
                selected: i == selected,
                button: true,
                child: Material(
                  color: i == selected ? AppColors.surface : Colors.transparent,
                  borderRadius: BorderRadius.circular(9),
                  child: InkWell(
                    onTap: () => onChanged(i),
                    borderRadius: BorderRadius.circular(9),
                    child: Center(
                      child: Text(
                        labels[i],
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppText.display(
                          size: AppTextSize.body,
                          weight: i == selected ? FontWeight.w600 : FontWeight.w500,
                          color: i == selected ? AppColors.green700 : Colors.white,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// 52px search box; filtering happens in the screen.
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
        fillColor: AppColors.surface,
        constraints: const BoxConstraints(minHeight: 52),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 15),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: const BorderSide(color: AppColors.green600, width: 1.5)),
      ),
    );
  }
}

/// One catalogue item: name over its stock, and a Borrow link. The whole row
/// opens the borrow sheet.
class _EquipmentRow extends StatelessWidget {
  final Equipment item;
  final VoidCallback onBorrow;
  final bool filipino;

  const _EquipmentRow({required this.item, required this.filipino, required this.onBorrow});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final stock = item.availableQuantity;
    final out = stock <= 0;

    final (String stockText, StatusTone tone) = out
        ? (trEn(f, 'Unavailable'), StatusTone.grey)
        : stock == 1
            ? (trEn(f, 'Only 1 left'), StatusTone.amber)
            : (trEn(f, '{n} available').replaceAll('{n}', '$stock'), StatusTone.green);

    return Opacity(
      opacity: out ? .55 : 1,
      child: InkWell(
        onTap: out ? null : onBorrow,
        canRequestFocus: false,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 68),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(item.name, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3)),
                      const SizedBox(height: AppSpacing.xs),
                      StatusLine(label: stockText, tone: tone),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: out ? null : onBorrow,
                  style: TextButton.styleFrom(
                    foregroundColor: AppColors.green700,
                    minimumSize: const Size(44, 44),
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                    textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
                  ),
                  child: Text(trEn(f, 'Borrow')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The way into an uncatalogued request. Its own row rather than a field in
/// the sheet: opening the sheet without an item is what makes sending both an
/// `equipment_id` and an `other_equipment_text` unrepresentable.
class _OtherEquipmentRow extends StatelessWidget {
  final VoidCallback onTap;
  final bool filipino;

  const _OtherEquipmentRow({required this.onTap, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return InkWell(
      onTap: onTap,
      canRequestFocus: false,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 68),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(trEn(f, 'Need something else?'),
                        style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3)),
                    const SizedBox(height: AppSpacing.xs),
                    Text(trEn(f, "Ask for an item that's not on this list"), style: AppText.detail()),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
            ],
          ),
        ),
      ),
    );
  }
}

/// A loan still in progress: what and how many, when it was sent, where it
/// stands and since when, its steps, and what the resident can do.
class _BorrowRequestCard extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;

  /// Resolves to `true` only once MDRRMO has accepted the withdrawal — the
  /// dialog waits for it before saying anything happened.
  final Future<bool> Function() onCancel;

  /// Bytes for one handover stage, or null when the fetch found nothing. Only
  /// called for a stage the row says exists.
  final Future<List<int>?> Function(String stage) loadPhoto;

  /// Dials MDRRMO; null hides the button (no number in the hotline list).
  final VoidCallback? onCall;

  const _BorrowRequestCard({
    required this.request,
    required this.filipino,
    required this.onCancel,
    required this.loadPhoto,
    this.onCall,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final status = borrowStatus(r, f);
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(_loanTitle(r), style: AppText.display(size: AppTextSize.cardTitle, weight: FontWeight.w600, height: 1.3)),
          const SizedBox(height: AppSpacing.xs),
          Text(
            [
              if (r.createdAt != null) tr(f, 'track.sent').replaceAll('{date}', formatStepTime(r.createdAt!)),
              trEn(f, r.fulfillmentMethod == 'Delivery' ? 'Delivery' : 'Pickup'),
            ].join(' · '),
            style: AppText.detail(),
          ),
          // Rows filed before `purpose` existed have none, and the card says
          // nothing rather than showing an empty line.
          if (r.purpose != null && r.purpose!.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            _Purpose(purpose: r.purpose!, filipino: f),
          ],
          const SizedBox(height: AppSpacing.lg),
          StatusLine(
            label: status.label,
            tone: status.tone,
            large: true,
            updatedAt: r.updatedAt ?? r.createdAt,
            filipino: f,
          ),
          const SizedBox(height: 6),
          Text(status.next, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
          const SizedBox(height: AppSpacing.lg),
          r.id == null
              ? Text(trEn(f, 'Sending...'), style: AppText.body(size: 15, color: AppColors.inkMuted))
              : BorrowProgressSteps(request: r, filipino: f),
          // Staff photograph the item at the counter; the resident only reads
          // it back. A row with neither photo draws nothing at all.
          if (r.id != null && (r.hasReleasePhoto || r.hasReturnPhoto)) ...[
            const SizedBox(height: 12),
            _HandoverPhotos(
              filipino: f,
              hasRelease: r.hasReleasePhoto,
              releaseLabel: tr(f, r.fulfillmentMethod == 'Delivery' ? 'status.delivered' : 'status.picked_up'),
              hasReturn: r.hasReturnPhoto,
              loadPhoto: loadPhoto,
            ),
          ],
          if (onCall != null) ...[
            const SizedBox(height: AppSpacing.lg),
            AppButton(label: trEn(f, 'Call MDRRMO'), icon: Icons.call_rounded, onPressed: onCall),
          ],
          // Pending and Approved only, matching the server's CANCELLABLE_FROM.
          // An id-less row is still in flight, so there is nothing to cancel yet.
          if (r.id != null && r.status.isCancellable) ...[
            const SizedBox(height: AppSpacing.sm),
            AppButton(
              label: tr(f, 'common.cancel_request'),
              style: AppButtonStyle.cancel,
              // No ref number on a borrowing — the dialog reads "Cancel request?".
              onPressed: () => showCancelDialog(context, '', onCancel, filipino: f),
            ),
          ],
        ],
      ),
    );
  }
}

/// A finished loan, one row: "Returned · Sep 26", MDRRMO's reason when it was
/// not approved, the handover photos when staff took any, and Borrow again.
class _PastLoanRow extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;
  final Future<List<int>?> Function(String stage) loadPhoto;

  /// Opens this item's borrow sheet; null when it is no longer in the catalogue.
  final VoidCallback? onBorrowAgain;

  const _PastLoanRow({required this.request, required this.filipino, required this.loadPhoto, this.onBorrowAgain});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final status = borrowStatus(r, f);
    final at = r.updatedAt ?? r.createdAt;

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 68),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_loanTitle(r), style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3)),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    [status.label, if (at != null) '${formatMonthShort(at)} ${at.toLocal().day}'].join(' · '),
                    style: AppText.detail(),
                  ),
                  if (r.status == BorrowStatus.denied) ...[
                    const SizedBox(height: AppSpacing.xs),
                    Text(status.next, style: AppText.detail()),
                  ],
                  if (r.id != null && (r.hasReleasePhoto || r.hasReturnPhoto)) ...[
                    const SizedBox(height: 12),
                    _HandoverPhotos(
                      filipino: f,
                      hasRelease: r.hasReleasePhoto,
                      releaseLabel: tr(f, r.fulfillmentMethod == 'Delivery' ? 'status.delivered' : 'status.picked_up'),
                      hasReturn: r.hasReturnPhoto,
                      loadPhoto: loadPhoto,
                    ),
                  ],
                ],
              ),
            ),
            if (onBorrowAgain != null)
              TextButton(
                onPressed: onBorrowAgain,
                style: TextButton.styleFrom(
                  foregroundColor: AppColors.green700,
                  minimumSize: const Size(44, 44),
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
                ),
                child: Text(trEn(f, 'Borrow again')),
              ),
          ],
        ),
      ),
    );
  }
}

/// "Wheelchair × 1".
String _loanTitle(BorrowRequest r) => '${r.itemLabel} × ${r.quantity}';

/// "Reason: <what the resident said it is for>".
class _Purpose extends StatelessWidget {
  final String purpose;
  final bool filipino;

  const _Purpose({required this.purpose, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Text.rich(
      TextSpan(children: [
        TextSpan(text: '${trEn(filipino, 'Reason:')} ', style: const TextStyle(fontWeight: FontWeight.w600)),
        TextSpan(text: purpose),
      ]),
      style: AppText.body(color: AppColors.ink, height: 1.45),
    );
  }
}

/// The photographs staff took when the item changed hands. Display only —
/// POST /borrowings/{id}/photo is behind `is.admin`, and this app has no way
/// to add or replace one.
class _HandoverPhotos extends StatelessWidget {
  final bool hasRelease;

  /// The loan's own word for the release step: "Picked up" or "Delivered".
  final String releaseLabel;
  final bool hasReturn;
  final Future<List<int>?> Function(String stage) loadPhoto;
  final bool filipino;

  const _HandoverPhotos({
    required this.filipino,
    required this.hasRelease,
    required this.releaseLabel,
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
                label: releaseLabel,
                stage: 'release',
                loadPhoto: loadPhoto,
              ),
            if (hasRelease && hasReturn) const SizedBox(width: 10),
            if (hasReturn)
              _HandoverThumbnail(
                label: tr(filipino, 'status.returned'),
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
        child: AppSpinner(color: AppColors.green700, size: 18),
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
                // The ceiling the stepper stops at, said before it is hit.
                Text(
                  _uncatalogued ? _t('How many you need') : _t('Up to {n}').replaceAll('{n}', '$_maxQuantity'),
                  style: AppText.detail(),
                ),
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
