library serbis.screens.services;

import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/motion.dart';
import '../widgets/service_widgets.dart';
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';
import 'service_form_page.dart';

/// The Services tab: one large, labelled tile per service the account may
/// file. Tapping a tile opens that service's form on a page of its own.
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

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.appState,
      builder: (context, _) {
        final f = widget.appState.language == AppLanguage.filipino;
        final tiles = _tiles();
        // The ambulance alone in the catalogue still means a catalogue that
        // loaded; only a truly empty one is a failed load.
        final loadFailed = !_loading && widget.appState.services.isEmpty;

        return ListView(
          padding: EdgeInsets.zero,
          children: [
            AppHeader(
              onNotificationsTap: widget.onOpenNotifications,
              onProfileTap: widget.onOpenProfile,
              filipino: f,
            ),
            const SizedBox(height: 22),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 22),
              child: SectionHeader(title: tr(f, 'services.title')),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 0, 22, 16),
              child: Text(
                tr(f, 'services.grid_intro'),
                style: AppText.body(
                    size: 14, color: AppColors.inkMuted, height: 1.5),
              ),
            ),
            if (_loading && widget.appState.services.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 30),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (loadFailed)
              Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 22, vertical: 12),
                child: Row(
                  children: [
                    const Icon(Icons.wifi_off_rounded,
                        size: 20, color: AppColors.inkMuted),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        "Couldn't load services. Check your connection and try again.",
                        style:
                            AppText.body(size: 13, color: AppColors.inkMuted),
                      ),
                    ),
                    TextButton(
                      onPressed: () {
                        setState(() => _loading = true);
                        _load();
                      },
                      child: const Text('Retry'),
                    ),
                  ],
                ),
              )
            else
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: _TileGrid(
                  children: [
                    for (final service in tiles)
                      _ServiceTile(
                        key: ValueKey('service-tile-${service.id}'),
                        service: service,
                        filipino: f,
                        onTap: () => _open(service),
                      ),
                  ],
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 22, 22, 110),
              child: SafetyNotice(filipino: f),
            ),
          ],
        );
      },
    );
  }
}

/// Two tiles to a row, one on a narrow screen or at a large system text size,
/// where two columns would leave each name a few letters per line.
class _TileGrid extends StatelessWidget {
  final List<Widget> children;

  const _TileGrid({required this.children});

  static const double _gap = 12;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final oneColumn = constraints.maxWidth < 280 ||
            MediaQuery.textScalerOf(context).scale(14) > 20;
        final columns = oneColumn ? 1 : 2;

        final rows = <Widget>[];
        for (var i = 0; i < children.length; i += columns) {
          final cells = <Widget>[];
          for (var c = 0; c < columns; c++) {
            if (c > 0) cells.add(const SizedBox(width: _gap));
            cells.add(Expanded(
                child: i + c < children.length
                    ? children[i + c]
                    : const SizedBox.shrink()));
          }
          if (rows.isNotEmpty) rows.add(const SizedBox(height: _gap));
          // Equal heights within a row, so a two-line name and a three-line name
          // sit as one row of tiles rather than a ragged pair.
          rows.add(IntrinsicHeight(
            child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: cells),
          ));
        }
        return Column(children: rows);
      },
    );
  }
}

class _ServiceTile extends StatelessWidget {
  final ServiceCatalogItem service;
  final bool filipino;
  final VoidCallback onTap;

  const _ServiceTile(
      {super.key,
      required this.service,
      required this.filipino,
      required this.onTap});

  @override
  Widget build(BuildContext context) {
    final badge = badgeForServiceCode(service.code);
    final name = service.displayName(filipino);

    return Semantics(
      button: true,
      label: name,
      onTap: onTap,
      excludeSemantics: true,
      child: PressableScale(
        child: Material(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(18),
            child: Container(
              constraints: const BoxConstraints(minHeight: 124),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.line, width: 1.5),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  IconBadge(
                      icon: badge.icon,
                      bg: badge.bg,
                      fg: badge.fg,
                      size: 46,
                      iconSize: 24,
                      radius: 14),
                  const SizedBox(height: 10),
                  Text(
                    name,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.display(size: 14.5, height: 1.25),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
