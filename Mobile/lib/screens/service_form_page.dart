import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import 'service_drafts.dart';
import 'service_request_form.dart';

/// One service's form on a page of its own, opened from the Services grid. The
/// form draws its own title header, step bar and pinned footer; the answers stay
/// in [drafts], so going back and forth costs the resident nothing.
class ServiceFormPage extends StatefulWidget {
  final AppState appState;
  final AppUser user;
  final ServiceCatalogItem service;
  final ServiceDrafts drafts;
  final VoidCallback onSubmitted;
  final VoidCallback onOpenNotifications;

  const ServiceFormPage({
    super.key,
    required this.appState,
    required this.user,
    required this.service,
    required this.drafts,
    required this.onSubmitted,
    required this.onOpenNotifications,
  });

  @override
  State<ServiceFormPage> createState() => _ServiceFormPageState();
}

class _ServiceFormPageState extends State<ServiceFormPage> {
  final _form = GlobalKey<ServiceRequestFormState>();

  @override
  Widget build(BuildContext context) {
    // Back steps through a stepped form before it leaves the page.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        final form = _form.currentState;
        if (form != null && form.canStepBack) {
          form.handleBack();
        } else {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        // Listening, so a language change made elsewhere relabels the form and a
        // catalogue refetched in the background swaps in the translated name.
        body: ListenableBuilder(
          listenable: widget.appState,
          builder: (context, _) {
            // Re-resolved by id: `service` was captured when the tile was tapped
            // and goes stale the moment the catalogue reloads in another language.
            final current = widget.appState.services.where((s) => s.id == widget.service.id).firstOrNull ?? widget.service;

            return ServiceRequestForm(
              key: _form,
              appState: widget.appState,
              user: widget.user,
              service: current,
              drafts: widget.drafts,
              onSubmitted: () {
                Navigator.of(context).pop();
                widget.onSubmitted();
              },
              onExit: () => Navigator.of(context).pop(),
              onOpenNotifications: widget.onOpenNotifications,
              // The reloaded catalogue no longer has this service.
              onServiceUnavailable: () => Navigator.of(context).pop(),
            );
          },
        ),
      ),
    );
  }
}
