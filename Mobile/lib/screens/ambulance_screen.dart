import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
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

  /// The app bar's back button (goes to Home).
  final VoidCallback? onBack;

  const AmbulanceScreen({
    super.key,
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

        // The form scrolls its own cards and pins Submit; this only adds the bar.
        return Column(
          children: [
            _CompactAppBar(filipino: f, onBack: widget.onBack),
            Expanded(
              child: ServiceRequestForm(
                appState: widget.appState,
                user: widget.user,
                service: ambulance,
                drafts: widget.drafts,
                onSubmitted: widget.onSubmitted,
                onOpenHotlines: widget.onOpenLibrary,
              ),
            ),
          ],
        );
      },
    );
  }
}

/// Back button, title and subtitle on the brand gradient. Shorter than
/// [AppHeader] so the form gets the screen.
class _CompactAppBar extends StatelessWidget {
  final bool filipino;
  final VoidCallback? onBack;

  const _CompactAppBar({required this.filipino, this.onBack});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(AppSpacing.lg, AppLayout.readerHeaderTop, AppSpacing.lg, 18),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.xxl)),
      ),
      child: Row(
        children: [
          if (onBack != null) ...[
            Material(
              color: Colors.white.withValues(alpha: .14),
              shape: CircleBorder(side: BorderSide(color: Colors.white.withValues(alpha: .3))),
              child: IconButton(
                onPressed: onBack,
                tooltip: trEn(f, 'Back'),
                icon: const Icon(Icons.chevron_left_rounded, color: Colors.white),
                constraints: const BoxConstraints.tightFor(width: 44, height: 44),
              ),
            ),
            const SizedBox(width: AppSpacing.md),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  trEn(f, 'Request an ambulance'),
                  style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: Colors.white),
                ),
                const SizedBox(height: 2),
                Text(
                  trEn(f, 'Non-emergency medical transport'),
                  style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .88)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
