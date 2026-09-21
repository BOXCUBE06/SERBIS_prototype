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
import '../widgets/offline_banner.dart';
import '../widgets/shared_widgets.dart';

/// Browse the equipment MDRRMO lends out and file a loan request, or check the
/// status of ones already filed. A request that is still Pending or Approved
/// can be withdrawn from here — everything else on the record stays with
/// MDRRMO, since `borrowings` exposes `update`/`destroy` to `is.admin` only.
class BorrowEquipmentScreen extends StatefulWidget {
  final AppState appState;

  /// The signed-in resident. Only used for the delivery-address "Same as my
  /// address" checkbox (MDRRMO feedback, 2026-09-19) — everything else this
  /// screen and its sheet do reads from [appState].
  final AppUser user;

  /// True when this is the Borrow tab: it draws the app header in place of a
  /// back-button app bar, and follows the store so a loan MDRRMO approves shows
  /// up without leaving the tab.
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

  @override
  void initState() {
    super.initState();
    _loadEquipment();
    _loadMine();
    if (widget.embedded) {
      widget.appState.addListener(_syncMine);
    }
  }

  @override
  void dispose() {
    if (widget.embedded) {
      widget.appState.removeListener(_syncMine);
    }
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
        appState: widget.appState,
        user: widget.user,
        item: item,
      ),
    );

    if (filed == null || !mounted) return;

    setState(() {
      _myRequests = List.of(widget.appState.borrowRequests);
      _showMine = true;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      // Reads back what the resident typed on the uncatalogued path, since
      // there is no catalogue name to confirm the request against.
      SnackBar(content: Text('Request filed for ${item?.name ?? filed.itemLabel}. MDRRMO will review it.')),
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
              title: Text('Borrow Equipment', style: AppText.display(size: 17)),
            ),
      body: Column(
        children: [
          if (widget.embedded)
            AppHeader(
              onNotificationsTap: widget.onOpenNotifications,
              onProfileTap: widget.onOpenProfile,
              filipino: f,
            ),
          if (widget.embedded)
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 22, 22, 0),
              child: SectionHeader(title: tr(f, 'borrow.title')),
            ),
          if (widget.appState.borrowIsOffline)
            OfflineBanner(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
          Padding(
            padding: EdgeInsets.fromLTRB(22, widget.embedded ? 0 : 16, 22, 0),
            child: _SegmentedToggle(
              leftLabel: 'Available',
              rightLabel: 'My Requests (${_myRequests.length})',
              rightSelected: _showMine,
              onChanged: (mine) => setState(() => _showMine = mine),
            ),
          ),
          Expanded(
            child: _showMine ? _buildMine(f) : _buildAvailable(f),
          ),
        ],
      ),
    );
  }

  Widget _buildAvailable(bool f) {
    if (_loadingEquipment) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_equipmentError != null && _equipment.isEmpty) {
      return _ErrorState(
        message: _equipmentError!,
        onRetry: () {
          setState(() => _loadingEquipment = true);
          _loadEquipment();
        },
      );
    }

    // Reachable from the empty catalogue too: an office with nothing listed is
    // exactly when a resident has to name the item themselves.
    if (_equipment.isEmpty) {
      return ListView(
        padding: const EdgeInsets.fromLTRB(22, 16, 22, 110),
        children: [
          const _EmptyState(
            icon: Icons.inventory_2_outlined,
            title: 'Nothing available right now',
            body: 'MDRRMO has no equipment listed for loan at the moment.',
          ),
          const SizedBox(height: 12),
          _OtherEquipmentCard(onTap: () => _openBorrowSheet(null)),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(22, 16, 22, 110),
      children: [
        ..._equipment.map((item) => Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: _EquipmentCard(
                item: item,
                onBorrow: item.availableQuantity > 0 ? () => _openBorrowSheet(item) : null,
              ),
            )),
        _OtherEquipmentCard(onTap: () => _openBorrowSheet(null)),
      ],
    );
  }

  Widget _buildMine(bool f) {
    if (_loadingMine && _myRequests.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_myRequests.isEmpty) {
      return const _EmptyState(
        icon: Icons.assignment_outlined,
        title: 'No borrow requests yet',
        body: 'Items you request from the Available tab will show up here.',
      );
    }

    return RefreshIndicator(
      color: AppColors.green700,
      onRefresh: _loadMine,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(22, 16, 22, 110),
        children: [
          if (widget.appState.borrowRequestsFromCache)
            StaleDataNote(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
          ..._myRequests.map((r) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _BorrowRequestCard(
                  request: r,
                  filipino: f,
                  onCancel: () => widget.appState.cancelBorrowRequest(r.id),
                  loadPhoto: (stage) => widget.appState.borrowPhoto(r.id!, stage),
                ),
              )),
        ],
      ),
    );
  }
}

/// Two mutually exclusive choices in a pill. Takes its labels rather than
/// naming the tabs, so the borrow sheet's fulfilment and borrower-type pickers
/// are this control and not two more copies of it.
class _SegmentedToggle extends StatelessWidget {
  final String leftLabel;
  final String rightLabel;

  /// True when the right-hand segment is the active one.
  final bool rightSelected;
  final ValueChanged<bool> onChanged;
  final bool enabled;

  const _SegmentedToggle({
    required this.leftLabel,
    required this.rightLabel,
    required this.rightSelected,
    required this.onChanged,
    this.enabled = true,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(30)),
      child: Row(
        children: [
          Expanded(child: _segment(leftLabel, !rightSelected, () => onChanged(false))),
          Expanded(child: _segment(rightLabel, rightSelected, () => onChanged(true))),
        ],
      ),
    );
  }

  Widget _segment(String label, bool active, VoidCallback onTap) {
    return GestureDetector(
      onTap: enabled ? onTap : null,
      behavior: HitTestBehavior.opaque,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: active ? AppColors.surface : Colors.transparent,
          borderRadius: BorderRadius.circular(26),
          boxShadow: active
              ? [BoxShadow(color: AppColors.green900.withOpacity(.06), blurRadius: 8, offset: const Offset(0, 2))]
              : null,
        ),
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: AppText.display(size: 12.5, weight: FontWeight.w600, color: active ? AppColors.ink : AppColors.inkMuted),
        ),
      ),
    );
  }
}

class _EquipmentCard extends StatelessWidget {
  final Equipment item;
  final VoidCallback? onBorrow;

  const _EquipmentCard({required this.item, this.onBorrow});

  @override
  Widget build(BuildContext context) {
    final out = item.availableQuantity <= 0;

    return AppCard(
      child: Row(
        children: [
          const IconBadge(icon: Icons.medical_services_outlined, bg: AppColors.green50, fg: AppColors.green700),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.name, style: AppText.display(size: 14.5)),
                const SizedBox(height: 2),
                Text(
                  out ? 'None available right now' : '${item.availableQuantity} available',
                  style: AppText.body(size: 12, color: out ? AppColors.red600 : AppColors.inkMuted),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          OutlinedButton(
            onPressed: onBorrow,
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.green700,
              side: const BorderSide(color: AppColors.green700),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            child: const Text('Borrow'),
          ),
        ],
      ),
    );
  }
}

/// The way into an uncatalogued request. Its own card rather than a field in
/// the sheet: opening the sheet without an item is what makes sending both an
/// `equipment_id` and an `other_equipment_text` unrepresentable.
class _OtherEquipmentCard extends StatelessWidget {
  final VoidCallback onTap;

  const _OtherEquipmentCard({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Row(
        children: [
          const IconBadge(icon: Icons.add_circle_outline, bg: AppColors.grey50, fg: AppColors.inkMuted),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Need something else?', style: AppText.display(size: 14.5)),
                const SizedBox(height: 2),
                Text(
                  'Ask for an item that is not on this list.',
                  style: AppText.body(size: 12, color: AppColors.inkMuted),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          OutlinedButton(
            onPressed: onTap,
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.green700,
              side: const BorderSide(color: AppColors.green700),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            child: const Text('Request'),
          ),
        ],
      ),
    );
  }
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
                      style: AppText.display(size: 14.5),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      request.id == null
                          ? 'Sending...'
                          : request.createdAt == null
                              ? 'Filed'
                              : 'Filed ${formatTimelineTime(request.createdAt!, filipino)}',
                      style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                    ),
                  ],
                ),
              ),
              _StatusChip(request.status),
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
                    style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.45),
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
              decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(10)),
              child: Text(request.denialReason!, style: AppText.body(size: 12, color: AppColors.red600, height: 1.5)),
            ),
          ],
          if (request.dueDate != null && !request.status.isTerminal) ...[
            const SizedBox(height: 10),
            Builder(builder: (context) {
              // Prominent, not a small caption line — MDRRMO feedback,
              // 2026-09-17. Same three-tier colouring the admin panel's own
              // countdown chip uses: red once overdue, amber inside the
              // 1-day reminder window, neutral otherwise.
              final label = dueLabel(request.dueDate!);
              final overdue = label.endsWith('overdue');
              final urgent = label == 'Due today' || label == 'Due tomorrow';
              final bg = overdue ? AppColors.red50 : (urgent ? AppColors.amber50 : AppColors.grey50);
              final fg = overdue ? AppColors.red600 : (urgent ? AppColors.amber600 : AppColors.inkMuted);
              return Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
                decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10)),
                child: Row(
                  children: [
                    Icon(Icons.event_outlined, size: 16, color: fg),
                    const SizedBox(width: 8),
                    Text(
                      label,
                      style: AppText.body(size: 13, weight: FontWeight.w700, color: fg),
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

  const _HandoverPhotos({
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
          'Handover photos',
          style: AppText.display(size: 12, color: AppColors.inkMuted),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            if (hasRelease)
              _HandoverThumbnail(
                label: 'Released',
                stage: 'release',
                loadPhoto: loadPhoto,
              ),
            if (hasRelease && hasReturn) const SizedBox(width: 10),
            if (hasReturn)
              _HandoverThumbnail(
                label: 'Returned',
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
              borderRadius: BorderRadius.circular(10),
            ),
            child: _tile(),
          ),
        ),
        const SizedBox(height: 5),
        Text(
          widget.label,
          style: AppText.body(size: 11, color: AppColors.inkMuted),
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

class _StatusChip extends StatelessWidget {
  final BorrowStatus status;
  const _StatusChip(this.status);

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: status.bg, borderRadius: BorderRadius.circular(30)),
      child: Text(
        status.label.toUpperCase(),
        style: AppText.display(size: 9.5, weight: FontWeight.w700, color: status.fg, letterSpacing: .4),
      ),
    );
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
            Text(title, style: AppText.display(size: 15), textAlign: TextAlign.center),
            const SizedBox(height: 6),
            Text(body, style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.5), textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const _ErrorState({required this.message, required this.onRetry});

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
            Text(message, style: AppText.body(size: 12.5, color: AppColors.inkMuted), textAlign: TextAlign.center),
            const SizedBox(height: 12),
            TextButton(onPressed: onRetry, child: const Text('Retry')),
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
    return Text(text, style: AppText.display(size: 13, weight: FontWeight.w600));
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

  const _BorrowSheet({
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

  /// Off by default — checking it fills [_address] from the account's own
  /// address once (MDRRMO feedback, 2026-09-19); the field stays editable
  /// either way, since a delivery is often to somewhere else.
  bool _deliveryAddressIsMyAddress = false;

  bool _submitting = false;
  String? _error;
  String? _purposeError;
  String? _otherItemError;
  String? _addressError;

  bool get _uncatalogued => widget.item == null;

  int get _maxQuantity => widget.item?.availableQuantity ?? _uncataloguedMaxQuantity;

  @override
  void initState() {
    super.initState();
    // Clears the "tell MDRRMO..." error as soon as there is something to send,
    // rather than leaving a red field under text that would now be accepted.
    _purpose.addListener(() => _clearIfFilled(_purpose, _purposeError, () => _purposeError = null));
    _otherItem.addListener(() => _clearIfFilled(_otherItem, _otherItemError, () => _otherItemError = null));
    _address.addListener(() => _clearIfFilled(_address, _addressError, () => _addressError = null));
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
        _purposeError = purpose.isEmpty ? 'Tell MDRRMO what you need this for.' : null;
        _otherItemError = missingItem ? 'Name the item you need.' : null;
        _addressError = missingAddress ? 'Where should MDRRMO deliver it?' : null;
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
        _error = widget.appState.takeError() ?? 'Something went wrong. Please try again.';
      });
      return;
    }

    Navigator.pop(context, result);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        padding: const EdgeInsets.fromLTRB(22, 14, 22, 26),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        // The stepper plus a three-line field no longer clears the keyboard on
        // a short phone; without this the sheet overflows instead of scrolling.
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 18),
                  decoration: BoxDecoration(color: AppColors.line, borderRadius: BorderRadius.circular(4)),
                ),
              ),
              Text(
                _uncatalogued ? 'Request another item' : widget.item!.name,
                style: AppText.display(size: 17),
              ),
              const SizedBox(height: 4),
              Text(
                _uncatalogued
                    ? 'MDRRMO will check whether they can lend this.'
                    : '${widget.item!.availableQuantity} available to borrow',
                style: AppText.body(size: 12.5, color: AppColors.inkMuted),
              ),
              if (_uncatalogued) ...[
                const SizedBox(height: 16),
                AppTextField(
                  label: 'What do you need?',
                  hint: 'e.g. Portable generator',
                  controller: _otherItem,
                  maxLength: _otherItemMaxLength,
                  errorText: _otherItemError,
                  enabled: !_submitting,
                ),
              ],
              const SizedBox(height: 20),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _stepButton(Icons.remove_rounded, () => _step(-1)),
                  SizedBox(
                    width: 56,
                    child: Text(
                      '$_quantity',
                      textAlign: TextAlign.center,
                      style: AppText.display(size: 22),
                    ),
                  ),
                  _stepButton(Icons.add_rounded, () => _step(1)),
                ],
              ),
              const SizedBox(height: 20),
              AppTextField(
                label: 'What do you need it for?',
                hint: 'e.g. Barangay flood drill this weekend',
                controller: _purpose,
                lines: 3,
                maxLength: _purposeMaxLength,
                errorText: _purposeError,
                enabled: !_submitting,
              ),
              Text(
                'MDRRMO reviews this before approving the loan.',
                style: AppText.body(size: 11.5, color: AppColors.inkMuted),
              ),
              const SizedBox(height: 20),
              _FieldLabel('How will you get it?'),
              const SizedBox(height: 8),
              _SegmentedToggle(
                leftLabel: 'Pickup',
                rightLabel: 'Delivery',
                rightSelected: _delivery,
                enabled: !_submitting,
                onChanged: (delivery) => setState(() {
                  _delivery = delivery;
                  // The field is about to disappear; leaving its error behind
                  // would block a submit with nothing on screen to explain it.
                  if (!delivery) _addressError = null;
                }),
              ),
              if (_delivery) ...[
                const SizedBox(height: 12),
                CheckboxListTile(
                  value: _deliveryAddressIsMyAddress,
                  onChanged: _submitting
                      ? null
                      : (checked) => setState(() {
                            _deliveryAddressIsMyAddress = checked ?? false;
                            if (_deliveryAddressIsMyAddress) {
                              _address.text = widget.user.fullAddress;
                            }
                          }),
                  controlAffinity: ListTileControlAffinity.leading,
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  title: const Text(
                    'Same as my address',
                    style: TextStyle(fontSize: 14),
                  ),
                ),
                AppTextField(
                  label: 'Delivery address',
                  hint: 'House number, street, barangay',
                  controller: _address,
                  lines: 2,
                  maxLength: _addressMaxLength,
                  errorText: _addressError,
                  enabled: !_submitting,
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 14),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(11),
                  decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(10)),
                  child: Text(_error!, style: AppText.body(size: 12, color: AppColors.red600, height: 1.5)),
                ),
              ],
              const SizedBox(height: 20),
              AppButton(
                label: 'Request this item',
                onPressed: _confirm,
                loading: _submitting,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _stepButton(IconData icon, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(20)),
        alignment: Alignment.center,
        child: Icon(icon, size: 18, color: AppColors.ink),
      ),
    );
  }
}
