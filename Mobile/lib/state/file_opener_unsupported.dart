/// Web build. Nothing is ever saved there — `MaterialCache.isSupported` is
/// false — so there is no local file to open and the caller falls through to
/// the server copy.
Future<bool> openLocalFile(String path) async => false;
