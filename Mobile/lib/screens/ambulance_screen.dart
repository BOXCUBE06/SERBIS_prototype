import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';
import 'service_request_form.dart';
import 'unavailable_tab_screen.dart';

/// The Ambulance tab: straight to the ambulance booking form, with no list to
/// choose from first.
///
/// The ambulance is one row of the service catalogue like any other, so the
/// tab finds it there rather than assuming it. When the account's audience does
/// not include it, or the catalogue could not be loaded to find out, the tab
/// says which of the two it is.
class AmbulanceScreen extends StatefulWidget {
  final AppState appState;
  final AppUser user;
  final ServiceDrafts drafts;
  final VoidCallback onSubmitted;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const AmbulanceScreen({
    super.key,
    required this.appState,
    required this.user,
    required this.drafts,
    required this.onSubmitted,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  State<AmbulanceScreen> createState() => _AmbulanceScreenState();
}

class _AmbulanceScreenState extends State<AmbulanceScreen> {
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    await widget.appState.loadServices();
    if (mounted) setState(() => _loading = false);
  }

  ServiceCatalogItem? _ambulance() {
    for (final service in widget.appState.services) {
      if (service.formKind == ServiceFormKind.ambulance) return service;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.appState,
      builder: (context, _) {
        final f = widget.appState.language == AppLanguage.filipino;

        if (_loading && widget.appState.services.isEmpty) {
          return ListView(
            padding: EdgeInsets.zero,
            children: [
              AppHeader(
                onNotificationsTap: widget.onOpenNotifications,
                onProfileTap: widget.onOpenProfile,
                filipino: f,
              ),
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 60),
                child: Center(child: CircularProgressIndicator()),
              ),
            ],
          );
        }

        final ambulance = _ambulance();

        if (ambulance == null) {
          final loadFailed = widget.appState.services.isEmpty;
          return UnavailableTabScreen(
            icon: Icons.medical_services_outlined,
            title: tr(f, 'nav.ambulance'),
            message: tr(f, loadFailed ? 'tab.load_failed' : 'tab.ambulance_unavailable'),
            filipino: f,
            onOpenNotifications: widget.onOpenNotifications,
            onOpenProfile: widget.onOpenProfile,
            retryLabel: loadFailed ? tr(f, 'tab.retry') : null,
            onRetry: loadFailed
                ? () {
                    setState(() => _loading = true);
                    _load();
                  }
                : null,
          );
        }

        return ListView(
          padding: EdgeInsets.zero,
          children: [
            AppHeader(
              onNotificationsTap: widget.onOpenNotifications,
              onProfileTap: widget.onOpenProfile,
              filipino: f,
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 22, 22, 110),
              child: ServiceRequestForm(
                appState: widget.appState,
                user: widget.user,
                service: ambulance,
                drafts: widget.drafts,
                onSubmitted: widget.onSubmitted,
              ),
            ),
          ],
        );
      },
    );
  }
}
