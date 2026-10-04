import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';

/// Sits above every screen while the app cannot reach MDRRMO.
///
/// The app had no concept of being offline: a dead network produced an empty
/// Track screen with a cheerful "No requests yet" card, and a submit that never
/// left the phone looked like any other failure. This says which of the two is
/// happening, and how old the information underneath it is.
///
/// It states that requests cannot be sent, because nothing is queued: a
/// silently-queued ambulance request is more dangerous than a rejected one.
class OfflineBanner extends StatelessWidget {
  final bool filipino;

  /// When the visible data was last confirmed by the server. Null when nothing
  /// has ever been fetched on this device — then the banner says that instead
  /// of dating rows it does not have.
  final DateTime? lastUpdated;

  const OfflineBanner({super.key, required this.filipino, this.lastUpdated});

  @override
  Widget build(BuildContext context) {
    final at = lastUpdated;

    return Material(
      color: AppColors.red50,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.wifi_off_rounded, size: 18, color: AppColors.red600),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tr(filipino, 'offline.title'),
                      style: AppText.display(size: AppTextSize.small, weight: FontWeight.w700, color: AppColors.red600),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      at == null
                          ? tr(filipino, 'offline.never_updated')
                          : '${tr(filipino, 'offline.last_updated')} '
                              '${formatTimelineTime(at, filipino)}',
                      style: AppText.body(size: AppTextSize.small, color: const Color(0xFF7A3527), height: 1.4),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The same statement inside a screen: these rows are old, and this is how old.
/// Shown on Track when the list came off the device rather than the server.
class StaleDataNote extends StatelessWidget {
  final bool filipino;
  final DateTime? lastUpdated;

  const StaleDataNote({super.key, required this.filipino, this.lastUpdated});

  @override
  Widget build(BuildContext context) {
    final at = lastUpdated;

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: AppColors.paper,
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Row(
        children: [
          const Icon(Icons.history_rounded, size: 15, color: AppColors.inkFaint),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              at == null
                  ? tr(filipino, 'offline.saved_copy')
                  : '${tr(filipino, 'offline.saved_copy')} · '
                      '${tr(filipino, 'offline.last_updated')} '
                      '${formatTimelineTime(at, filipino)}',
              style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
            ),
          ),
        ],
      ),
    );
  }
}
