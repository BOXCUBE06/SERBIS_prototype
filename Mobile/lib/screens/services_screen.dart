library serbis.screens.services;

import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/feedback.dart';
import '../widgets/loading.dart';
import '../widgets/motion.dart';
import '../widgets/request_summary.dart' show SummaryCard;
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';
import 'service_form_page.dart';

/// The Services tab: one row per service the account may file, grouped under
/// category headings. Tapping a row opens that service's form on its own page.
///
/// The catalogue is already narrowed to this account's audience by the server
/// (`appState.services`), and "Others" is added here when the audience allows
/// it. The ambulance is left out: it has a tab of its own.
class ServicesScreen extends StatefulWidget {
  final AppState appState;

  /// The signed-in resident. The forms used to ask for a name and a number the
  /// account already holds; both now come from here.
  final AppUser user;
  final VoidCallback onSubmitted;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  /// The answers kept across forms. Shared with the Ambulance tab when the
  /// shell passes one, so the resident's ID is attached once for both; a screen
  /// built without one keeps its own.
  final ServiceDrafts? drafts;

  const ServicesScreen({
    super.key,
    required this.appState,
    required this.user,
    required this.onSubmitted,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.drafts,
  });

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  bool _loading = true;
  ServiceDrafts? _ownDrafts;
  final _search = TextEditingController();

  ServiceDrafts get _drafts =>
      widget.drafts ?? (_ownDrafts ??= ServiceDrafts(widget.user));

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _ownDrafts?.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    await widget.appState.loadServices();
    if (mounted) setState(() => _loading = false);
  }

  /// Read live from the store on every build. "Others" has no tbl_services row,
  /// so it never comes back from the catalogue and is appended here.
  List<ServiceCatalogItem> _tiles() => [
        ...widget.appState.services
            .where((s) => s.formKind != ServiceFormKind.ambulance),
        if (widget.appState.othersAllowed) const ServiceCatalogItem.others(),
      ];

  void _open(ServiceCatalogItem service) {
    Navigator.of(context).push(serbisRoute<void>(
      (_) => ServiceFormPage(
        appState: widget.appState,
        user: widget.user,
        service: service,
        drafts: _drafts,
        onSubmitted: widget.onSubmitted,
        onOpenNotifications: widget.onOpenNotifications,
      ),
    ));
  }

  /// Case-insensitive match on what the row shows, in the language it shows it.
  List<ServiceCatalogItem> _matching(List<ServiceCatalogItem> tiles, bool f) {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return tiles;
    return tiles
        .where((s) =>
            s.displayName(f).toLowerCase().contains(q) || s.displayDescription(f).toLowerCase().contains(q))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.appState,
      builder: (context, _) {
        final f = widget.appState.language == AppLanguage.filipino;
        final tiles = _matching(_tiles(), f);
        // The ambulance alone in the catalogue still means a catalogue that
        // loaded; only a truly empty one is a failed load.
        final loadFailed = !_loading && widget.appState.services.isEmpty;

        return CustomScrollView(
          slivers: [
            SliverPersistentHeader(
              pinned: true,
              delegate: TabHeader(
                title: tr(f, 'services.title'),
                subtitle: tr(f, 'services.subtitle'),
                filipino: f,
                onNotifications: widget.onOpenNotifications,
                onProfile: widget.onOpenProfile,
                bottom: _SearchField(
                  controller: _search,
                  hint: tr(f, 'services.search'),
                  clearLabel: tr(f, 'services.search_clear'),
                  onChanged: () => setState(() {}),
                ),
              ),
            ),
            SliverToBoxAdapter(
              // Phone-width on a tablet, the web build or a desktop window.
              child: Align(
                alignment: Alignment.topCenter,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 600),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, AppLayout.navClearance),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: _body(f, tiles, loadFailed),
                    ),
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  List<Widget> _body(bool f, List<ServiceCatalogItem> tiles, bool loadFailed) {
    if (_loading && widget.appState.services.isEmpty) {
      return [SkeletonRows(count: 4, filipino: f)];
    }
    if (loadFailed) {
      return [
        LoadErrorBox(
          title: tr(f, 'services.load_failed'),
          body: tr(f, 'tab.load_failed'),
          filipino: f,
          onRetry: () {
            setState(() => _loading = true);
            _load();
          },
        ),
      ];
    }
    if (tiles.isEmpty) {
      return [
        EmptyState(
          title: tr(f, 'services.no_match').replaceAll('{q}', _search.text.trim()),
          body: tr(f, 'services.no_match_body'),
        ),
      ];
    }
    return [_GroupedServices(services: tiles, filipino: f, onOpen: _open)];
  }
}

/// White 52px search box on the green header; filters the list as the
/// resident types.
class _SearchField extends StatelessWidget {
  final TextEditingController controller;
  final String hint;
  final String clearLabel;
  final VoidCallback onChanged;

  const _SearchField({required this.controller, required this.hint, required this.clearLabel, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: BorderSide.none,
    );
    return TextField(
      controller: controller,
      onChanged: (_) => onChanged(),
      textInputAction: TextInputAction.search,
      // Fills the header's 52px slot exactly.
      expands: true,
      maxLines: null,
      textAlignVertical: TextAlignVertical.center,
      style: AppText.body(size: 16),
      decoration: InputDecoration(
        hintText: hint,
        hintStyle: AppText.body(size: 16, color: AppColors.inkFaint),
        filled: true,
        fillColor: AppColors.surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14),
        prefixIcon: const Icon(Icons.search_rounded, color: AppColors.inkMuted),
        suffixIcon: controller.text.isEmpty
            ? null
            : IconButton(
                tooltip: clearLabel,
                icon: const Icon(Icons.close_rounded, color: AppColors.inkMuted),
                onPressed: () {
                  controller.clear();
                  onChanged();
                },
              ),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: const BorderSide(color: AppColors.green600, width: 2)),
      ),
    );
  }
}

/// Services grouped under their category heading, in the office's order.
/// Unknown categories follow; "Others" (no category) comes last.
class _GroupedServices extends StatelessWidget {
  final List<ServiceCatalogItem> services;
  final bool filipino;
  final ValueChanged<ServiceCatalogItem> onOpen;

  const _GroupedServices({required this.services, required this.filipino, required this.onOpen});

  static const _order = ['infrastructure', 'rescue', 'relief', 'programs'];

  int _rank(String? category) {
    if (category == null) return _order.length + 1;
    final i = _order.indexOf(category);
    return i < 0 ? _order.length : i;
  }


  /// A category added in the admin panel has no translation; show its code
  /// with a capital first letter, the same sentence case as the rest.
  String _heading(String? category) {
    final key = 'services.category.${category ?? 'other'}';
    final text = tr(filipino, key);
    if (text != key) return text;
    return category!.isEmpty ? category : category[0].toUpperCase() + category.substring(1);
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String?, List<ServiceCatalogItem>>{};
    for (final service in services) {
      groups.putIfAbsent(service.category, () => []).add(service);
    }
    final keys = groups.keys.toList()..sort((a, b) => _rank(a).compareTo(_rank(b)));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final key in keys) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 4, 4, 10),
            child: Semantics(
              header: true,
              child: Text(_heading(key), style: AppText.display(size: AppTextSize.section, weight: FontWeight.w600, color: AppColors.sectionInk)),
            ),
          ),
          SummaryCard(children: [
            for (final service in groups[key]!)
              _ServiceRow(
                key: ValueKey('service-tile-${service.id}'),
                service: service,
                filipino: filipino,
                onTap: () => onOpen(service),
              ),
          ]),
          const SizedBox(height: 18),
        ],
      ],
    );
  }
}

/// One service: tonal icon tile, name, up to two lines of description, chevron.
class _ServiceRow extends StatelessWidget {
  final ServiceCatalogItem service;
  final bool filipino;
  final VoidCallback onTap;

  const _ServiceRow({super.key, required this.service, required this.filipino, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final name = service.displayName(filipino);
    final description = service.displayDescription(filipino);

    return Semantics(
      button: true,
      label: name,
      onTap: onTap,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 68),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(color: AppColors.greenSelected, borderRadius: BorderRadius.circular(10)),
                  child: Icon(iconForServiceCode(service.code), size: 20, color: AppColors.green700),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: AppText.display(size: 16, weight: FontWeight.w600, height: 1.3)),
                      if (description.isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text(
                          description,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppText.detail(),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
