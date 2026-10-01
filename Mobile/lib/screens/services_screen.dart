library serbis.screens.services;

import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/motion.dart';
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
                        trEn(f, "Couldn't load services. Check your connection and try again."),
                        style:
                            AppText.body(size: 13, color: AppColors.inkMuted),
                      ),
                    ),
                    TextButton(
                      onPressed: () {
                        setState(() => _loading = true);
                        _load();
                      },
                      child: Text(trEn(f, 'Retry')),
                    ),
                  ],
                ),
              )
            else
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: _GroupedServices(services: tiles, filipino: f, onOpen: _open),
              ),
            const SizedBox(height: 110),
          ],
        );
      },
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

  /// A category added in the admin panel has no translation; show it as sent.
  String _heading(String? category) {
    final key = 'services.category.${category ?? 'other'}';
    final text = tr(filipino, key);
    return text == key ? category! : text;
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String?, List<ServiceCatalogItem>>{};
    for (final service in services) {
      groups.putIfAbsent(service.category, () => []).add(service);
    }
    final keys = groups.keys.toList()..sort((a, b) => _rank(a).compareTo(_rank(b)));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final key in keys) ...[
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.md, bottom: AppSpacing.sm),
            child: Text(
              _heading(key).toUpperCase(),
              style: AppText.display(size: 12.5, weight: FontWeight.w700, color: AppColors.green900, letterSpacing: 0.6),
            ),
          ),
          for (final service in groups[key]!)
            _ServiceRow(
              key: ValueKey('service-tile-${service.id}'),
              service: service,
              filipino: filipino,
              onTap: () => onOpen(service),
            ),
        ],
      ],
    );
  }
}

/// One service: icon, name, its one-line description, and a chevron. Every
/// icon shares one neutral tint; the heading above carries the grouping.
class _ServiceRow extends StatelessWidget {
  final ServiceCatalogItem service;
  final bool filipino;
  final VoidCallback onTap;

  const _ServiceRow({super.key, required this.service, required this.filipino, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final name = service.displayName(filipino);
    final description = service.displayDescription(filipino);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Semantics(
        button: true,
        label: name,
        onTap: onTap,
        excludeSemantics: true,
        child: PressableScale(
          child: Material(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              onTap: onTap,
              borderRadius: BorderRadius.circular(14),
              child: Container(
                constraints: const BoxConstraints(minHeight: 64),
                padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.line, width: 1.5),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    IconBadge(
                      icon: iconForServiceCode(service.code),
                      bg: AppColors.grey50,
                      fg: AppColors.ink,
                      size: 40,
                      iconSize: 22,
                      radius: 12,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(name, style: AppText.display(size: 14.5, height: 1.25)),
                          if (description.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(
                              description,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: AppText.body(size: 12.5, color: AppColors.inkMuted, height: 1.35),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right_rounded, color: AppColors.inkFaint),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
