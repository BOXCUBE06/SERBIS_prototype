import 'package:open_filex/open_filex.dart';

import 'app_log.dart';

/// Device implementation. `open_filex` carries the Android `FileProvider` the
/// documents directory needs, so the path can be handed over as-is.
Future<bool> openLocalFile(String path) async {
  try {
    final result = await OpenFilex.open(path);
    if (result.type != ResultType.done) {
      // `noAppToOpen` and `fileNotFound` are the two that matter and they need
      // opposite responses — install a PDF viewer, versus re-download. The
      // caller collapses both into `false`, so this is the only place the
      // difference survives. The path is not logged: it ends in the document's
      // id, and which safety material a resident opened is theirs.
      AppLog.warn('materials', 'open saved file',
          reason: 'open_filex: ${result.type.name}');
    }
    return result.type == ResultType.done;
  } catch (error) {
    AppLog.error('materials', 'open saved file', error: error);
    return false;
  }
}
