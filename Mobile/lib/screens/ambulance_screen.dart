import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import '../widgets/loading.dart' show SkeletonCard;
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

  /// Opens the Library, where the emergency hotlines live.
  final VoidCallback? onOpenLibrary;

  /// Leaving the flow (X, or back on step 1): goes to Home.
  final VoidCallback? onBack;

  /// Lets the shell route Android back into the flow.
  final GlobalKey<ServiceRequestFormState>? formKey;

  const AmbulanceScreen({
    super.key,
    this.formKey,
    required this.appState,
    required this.user,
    required this.drafts,
    required this.onSubmitted,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    this.onOpenLibrary,
    this.onBack,
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
              // The shape of the first step while the catalogue loads.
              Padding(
                padding: const EdgeInsets.fromLTRB(AppLayout.gutter, AppSpacing.lg, AppLayout.gutter, 0),
                child: SkeletonCard(filipino: f),
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

        // The flow brings its own header and footer; the shell hides the nav.
        return ServiceRequestForm(
          key: widget.formKey,
          appState: widget.appState,
          user: widget.user,
          service: ambulance,
          drafts: widget.drafts,
          onSubmitted: widget.onSubmitted,
          onOpenHotlines: widget.onOpenLibrary,
          onExit: widget.onBack,
        );
      },
    );
  }
}
