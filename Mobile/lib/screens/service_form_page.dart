import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/account_store.dart';
import '../state/request_store.dart';
import '../widgets/shared_widgets.dart';
import 'service_drafts.dart';
import 'service_request_form.dart';

/// One service's form on a page of its own, opened from the Services grid. The
/// header's back arrow returns to the grid and stays pinned while the form
/// scrolls; the answers stay in [drafts], so going back and forth costs the
/// resident nothing.
class ServiceFormPage extends StatelessWidget {
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
  Widget build(BuildContext context) {
    return Scaffold(
      // Listening, so a language change made elsewhere relabels the form and a
      // catalogue refetched in the background swaps in the translated name.
      body: ListenableBuilder(
        listenable: appState,
        builder: (context, _) {
          final f = appState.language == AppLanguage.filipino;
          // Re-resolved by id: `service` was captured when the tile was tapped
          // and goes stale the moment the catalogue reloads in another language.
          final current = appState.services.where((s) => s.id == service.id).firstOrNull ?? service;

          // The header sits outside the list, so the back arrow stays under the
          // thumb however far down a long form the resident has scrolled.
          return Column(
            children: [
              AppHeader(
                onNotificationsTap: onOpenNotifications,
                onBack: () => Navigator.of(context).maybePop(),
                filipino: f,
              ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(22, 22, 22, 40),
                  children: [
                    ServiceRequestForm(
                      appState: appState,
                      user: user,
                      service: current,
                      drafts: drafts,
                      onSubmitted: () {
                        Navigator.of(context).maybePop();
                        onSubmitted();
                      },
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
