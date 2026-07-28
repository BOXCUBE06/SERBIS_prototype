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

  const InfoMaterial({
    required this.id,
    required this.title,
    required this.fileType,
    required this.sizeBytes,
    required this.url,
  });

  factory InfoMaterial.fromJson(Map<String, dynamic> json) {
    return InfoMaterial(
      id: _intOf(json['files_id'] ?? json['id']),
      title: (json['title'] as String?)?.trim() ?? 'Untitled',
      fileType: (json['file_type'] as String?)?.toLowerCase() ?? '',
      sizeBytes: _intOf(json['file_size']),
      url: (json['full_url'] as String?) ?? '',
    );
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
