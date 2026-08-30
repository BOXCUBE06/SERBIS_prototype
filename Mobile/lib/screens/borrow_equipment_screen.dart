library serbis.screens.borrow_equipment;

import 'package:flutter/material.dart';
import '../models/borrow_models.dart';
import '../models/request_models.dart' show formatTimelineTime;
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/form_inputs.dart';
import '../widgets/offline_banner.dart';
import '../widgets/shared_widgets.dart';

/// Browse the equipment MDRRMO lends out and file a loan request, or check the
/// status of ones already filed. There is no resident-facing cancel: the
/// backend only exposes `update`/`destroy` on `borrowings` to `is.admin`, so
/// once filed a request is read-only from here until MDRRMO acts on it.
class BorrowEquipmentScreen extends StatefulWidget {
  final AppState appState;

  const BorrowEquipmentScreen({super.key, required this.appState});

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

  Future<void> _openBorrowSheet(Equipment item) async {
    final filed = await showModalBottomSheet<BorrowRequest>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _BorrowSheet(appState: widget.appState, item: item),
    );

    if (filed == null || !mounted) return;

    setState(() {
      _myRequests = List.of(widget.appState.borrowRequests);
      _showMine = true;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Request filed for ${item.name}. MDRRMO will review it.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: AppBar(
        backgroundColor: AppColors.paper,
        elevation: 0,
        foregroundColor: AppColors.ink,
        title: Text('Borrow Equipment', style: AppText.display(size: 17)),
      ),
      body: Column(
        children: [
          if (widget.appState.borrowIsOffline)
            OfflineBanner(filipino: f, lastUpdated: widget.appState.borrowRequestsFetchedAt),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 16, 22, 0),
            child: _SegmentedToggle(
              showMine: _showMine,
              myCount: _myRequests.length,
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

    if (_equipment.isEmpty) {
      return const _EmptyState(
        icon: Icons.inventory_2_outlined,
        title: 'Nothing available right now',
        body: 'MDRRMO has no equipment listed for loan at the moment.',
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(22, 16, 22, 110),
      children: _equipment
          .map((item) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _EquipmentCard(
                  item: item,
                  onBorrow: item.availableQuantity > 0 ? () => _openBorrowSheet(item) : null,
                ),
              ))
          .toList(),
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
                child: _BorrowRequestCard(request: r, filipino: f),
              )),
        ],
      ),
    );
  }
}

class _SegmentedToggle extends StatelessWidget {
  final bool showMine;
  final int myCount;
  final ValueChanged<bool> onChanged;

  const _SegmentedToggle({
    required this.showMine,
    required this.myCount,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(30)),
      child: Row(
        children: [
          Expanded(child: _segment('Available', !showMine, () => onChanged(false))),
          Expanded(child: _segment('My Requests ($myCount)', showMine, () => onChanged(true))),
        ],
      ),
    );
  }

  Widget _segment(String label, bool active, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
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

class _BorrowRequestCard extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;

  const _BorrowRequestCard({required this.request, required this.filipino});

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
                      '${request.equipmentName ?? 'Equipment'} × ${request.quantity}',
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
            Row(
              children: [
                const Icon(Icons.event_outlined, size: 14, color: AppColors.inkFaint),
                const SizedBox(width: 8),
                Text(
                  'Due back ${request.dueDate!.month}/${request.dueDate!.day}/${request.dueDate!.year}',
                  style: AppText.body(size: 12, color: AppColors.inkMuted),
                ),
              ],
            ),
          ],
        ],
      ),
    );
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

/// Quantity picker + submit. A separate widget rather than a method on the
/// screen because it needs its own `setState` for the stepper and the
/// in-flight spinner without rebuilding the whole screen behind it.
class _BorrowSheet extends StatefulWidget {
  final AppState appState;
  final Equipment item;

  const _BorrowSheet({required this.appState, required this.item});

  @override
  State<_BorrowSheet> createState() => _BorrowSheetState();
}

class _BorrowSheetState extends State<_BorrowSheet> {
  /// Matches `purpose`'s column width. The server rejects anything longer, and
  /// finding that out only after a round trip loses what was typed past 255.
  static const _purposeMaxLength = 255;

  late int _quantity = widget.item.availableQuantity > 0 ? 1 : 0;
  final TextEditingController _purpose = TextEditingController();
  bool _submitting = false;
  String? _error;
  String? _purposeError;

  @override
  void initState() {
    super.initState();
    // Clears the "tell MDRRMO..." error as soon as there is something to send,
    // rather than leaving a red field under text that would now be accepted.
    _purpose.addListener(() {
      if (_purposeError != null && _purpose.text.trim().isNotEmpty) {
        setState(() => _purposeError = null);
      }
    });
  }

  @override
  void dispose() {
    _purpose.dispose();
    super.dispose();
  }

  void _step(int delta) {
    final next = _quantity + delta;
    if (next < 1 || next > widget.item.availableQuantity) return;
    setState(() => _quantity = next);
  }

  Future<void> _confirm() async {
    if (_submitting || _quantity < 1) return;

    // Checked here as well as on the server: `purpose` is `required` on
    // `POST /borrowings`, and a round trip to be told the box is empty is a
    // worse way to learn it than the field going red.
    final purpose = _purpose.text.trim();
    if (purpose.isEmpty) {
      setState(() => _purposeError = 'Tell MDRRMO what you need this for.');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    final result = await widget.appState.submitBorrowRequest(
      item: widget.item,
      quantity: _quantity,
      purpose: purpose,
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
              Text(widget.item.name, style: AppText.display(size: 17)),
              const SizedBox(height: 4),
              Text(
                '${widget.item.availableQuantity} available to borrow',
                style: AppText.body(size: 12.5, color: AppColors.inkMuted),
              ),
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
