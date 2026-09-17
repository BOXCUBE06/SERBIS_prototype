import 'package:flutter/material.dart';

/// A material published by MDRRMO through the admin panel and served by
/// `GET /api/info-materials`. These are files (PDF, image, doc), not the
/// in-app articles in `data/safety_files.dart` — those ship inside the binary
/// and are always readable offline.
@immutable
class InfoMaterial {
  final int id;
  final String title;
  final String fileType;
  final int sizeBytes;

  /// Absolute URL from the server's `full_url`. Empty for a material that is
  /// only known from the offline index — the server has not been reached this
  /// launch, so the copy on disk is all there is.
  final String url;

  /// When MDRRMO published it — the row's `created_at`. Null when the server
  /// did not send one, and the surfaces that show a date must say so rather
  /// than print a stand-in: this is the only date the Home screen has, and an
  /// invented one is exactly the placeholder this replaced.
  final DateTime? publishedAt;

  /// Whether MDRRMO has checked this material and stands behind it. Defaults
  /// to false everywhere, including for a row the server sent without the key:
  /// claiming a review that did not happen is the one wrong answer here.
  final bool verified;

  /// Who verified it, and in what capacity — null whenever [verified] is
  /// false, same as the server clears both the moment a mark is taken back.
  final String? verifiedByName;
  final String? verifiedByRole;

  const InfoMaterial({
    required this.id,
    required this.title,
    required this.fileType,
    required this.sizeBytes,
    required this.url,
    this.publishedAt,
    this.verified = false,
    this.verifiedByName,
    this.verifiedByRole,
  });

  factory InfoMaterial.fromJson(Map<String, dynamic> json) {
    return InfoMaterial(
      id: _intOf(json['files_id'] ?? json['id']),
      title: (json['title'] as String?)?.trim() ?? 'Untitled',
      fileType: (json['file_type'] as String?)?.toLowerCase() ?? '',
      sizeBytes: _intOf(json['file_size']),
      url: (json['full_url'] as String?) ?? '',
      // Laravel serialises UTC; without toLocal() a material published this
      // morning reads as yesterday evening in the Philippines.
      publishedAt: _dateOf(json['created_at']),
      // The column is cast to bool server-side, but an older build of the API
      // sends no key at all and 0/1 is still what a raw driver would give.
      verified: json['verified'] == true || json['verified'] == 1,
      verifiedByName: (json['verified_by_name'] as String?)?.trim(),
      verifiedByRole: (json['verified_by_role'] as String?)?.trim(),
    );
  }

  static DateTime? _dateOf(Object? value) {
    if (value is! String || value.isEmpty) return null;
    return DateTime.tryParse(value)?.toLocal();
  }

  static int _intOf(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? 0;
  }

  /// `1.2 MB`, `184 KB`, `— ` when the server sent no size.
  String get sizeLabel {
    if (sizeBytes <= 0) return '';
    if (sizeBytes < 1024) return '$sizeBytes B';
    if (sizeBytes < 1024 * 1024) return '${(sizeBytes / 1024).round()} KB';
    return '${(sizeBytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  String get typeLabel => fileType.isEmpty ? 'File' : fileType.toUpperCase();

  IconData get icon {
    switch (fileType) {
      case 'pdf':
        return Icons.picture_as_pdf_rounded;
      case 'png':
      case 'jpg':
      case 'jpeg':
        return Icons.image_rounded;
      case 'zip':
        return Icons.folder_zip_rounded;
      case 'doc':
      case 'docx':
        return Icons.description_rounded;
      default:
        return Icons.insert_drive_file_rounded;
    }
  }
}
