import 'package:url_launcher/url_launcher.dart';

import 'app_log.dart';
import 'file_opener_unsupported.dart'
    if (dart.library.io) 'file_opener_io.dart' as platform;

/// Hands a material to whatever the platform uses to display it.
///
/// M10/M11 made the download real but stopped there: the bytes were on disk and
/// the row said "Saved", and tapping it did nothing. A resident who downloaded
/// the Evacuation Center Map before a storm still could not look at it.
///
/// Two routes, because they fail independently. The saved copy is the one that
/// works with no signal, so it is tried first; the server copy is the fallback
/// for a device with no PDF viewer, and the only route on web, which has no
/// saved copies at all.
class FileOpener {
  const FileOpener();

  /// Opens a file already on this device. False when nothing installed can
  /// display it, when the file has gone, or on web, where there is no local
  /// file to open in the first place.
  Future<bool> openFile(String path) => platform.openLocalFile(path);

  /// Opens [url] in the browser or an external viewer. Works everywhere,
  /// including web, and needs a connection.
  Future<bool> openUrl(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !uri.hasScheme) {
      // A material row whose `full_url` is relative or empty. The resident sees
      // "nothing can open this", which is indistinguishable from having no
      // viewer installed — a backend problem wearing a device problem's face.
      AppLog.error('materials', 'open material URL',
          reason: 'malformed or schemeless URL');
      return false;
    }

    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (error) {
      // A missing platform implementation throws rather than returning false.
      // The URL is left out on purpose; see ApiService.downloadFile.
      AppLog.error('materials', 'open material URL', error: error);
      return false;
    }
  }
}
