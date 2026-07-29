import 'package:open_filex/open_filex.dart';

/// Device implementation. `open_filex` carries the Android `FileProvider` the
/// documents directory needs, so the path can be handed over as-is.
Future<bool> openLocalFile(String path) async {
  try {
    final result = await OpenFilex.open(path);
    return result.type == ResultType.done;
  } catch (_) {
    return false;
  }
}
