import 'package:flutter/widgets.dart';

/// The four guided request forms, one class each.
///
/// These used to be a `Map<String, TextEditingController>` keyed by strings
/// like `'amb_patient'`, filled by `_ctrl('amb_patient')` on the way in and
/// read by `_text('amb_patient')` on the way out. Nothing connected the two:
/// `_ctrl` creates a controller on demand, so a field could be collected and
/// never read and the analyzer had nothing to say about it. That is exactly
/// what M2 was — five fields typed in by residents and dropped before the
/// request was sent.
///
/// Here every field is a named property. A field that is rendered but left out
/// of [metaLines] is now visible in one screenful of code, and a rename that
/// misses one side does not compile.
sealed class ServiceFormData {
  /// The lines that become the request's description, in the order the
  /// dispatcher reads them. [serviceName] leads; [submittedLabel] closes.
  ///
  /// Deliberately English: this text is stored on the request and read in the
  /// admin panel, not shown back to the resident in their language.
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  });

  /// The callback number this form must carry, or null where a number is not
  /// part of the response. Empty means the resident left a required field
  /// blank — a dispatcher who cannot call back cannot dispatch.
  String? get requiredContactNumber;

  void dispose();
}

String _text(TextEditingController controller) => controller.text.trim();

String _or(TextEditingController controller, String fallback) {
  final value = _text(controller);
  return value.isEmpty ? fallback : value;
}

class AmbulanceFormData extends ServiceFormData {
  /// [patientName] prefills the field rather than replacing it. The account
  /// holder is the likeliest patient, not the certain one — a head of the
  /// family files for the household — so the field stays editable and the
  /// description still reads whatever ends up in it.
  AmbulanceFormData({String patientName = ''}) {
    patient.text = patientName;
  }

  final TextEditingController patient = TextEditingController();
  final TextEditingController pickup = TextEditingController();
  final TextEditingController destination = TextEditingController();
  final TextEditingController condition = TextEditingController();
  final TextEditingController contact = TextEditingController();

  @override
  String? get requiredContactNumber => _text(contact);

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        'Patient: ${_or(patient, 'Not specified')}',
        '${_or(pickup, 'Pick-up location not specified')} → '
            '${_or(destination, 'destination not specified')}',
        'Condition: ${_or(condition, 'Not described')}',
        'Contact: ${_text(contact)}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    patient.dispose();
    pickup.dispose();
    destination.dispose();
    condition.dispose();
    contact.dispose();
  }
}

class RoadFormData extends ServiceFormData {
  static const obstructionTypes = [
    'Fallen tree / branches',
    'Flooding / silt',
    'Landslide debris',
    'Other',
  ];

  final TextEditingController location = TextEditingController();
  final TextEditingController description = TextEditingController();
  String obstruction = obstructionTypes.first;

  /// A road obstruction is reported about a place, not about the reporter, and
  /// the form has never asked for a number.
  @override
  String? get requiredContactNumber => null;

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        _or(location, 'Location not specified'),
        'Obstruction: $obstruction',
        'Description: ${_or(description, 'No description provided')}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    location.dispose();
    description.dispose();
  }
}

class ReliefFormData extends ServiceFormData {
  static const assistanceTypes = [
    'Food packs',
    'Hygiene kits',
    'Drinking water',
    'Temporary shelter materials',
    'Other',
  ];

  /// The household head is the account holder by definition — the app is
  /// distributed one account per household — so this one is prefilled for the
  /// same reason the patient name is, with more confidence.
  ReliefFormData({String headName = ''}) {
    head.text = headName;
  }

  final TextEditingController head = TextEditingController();
  final TextEditingController address = TextEditingController();
  final TextEditingController householdSize = TextEditingController();
  final TextEditingController contact = TextEditingController();
  String assistance = assistanceTypes.first;

  @override
  String? get requiredContactNumber => _text(contact);

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        'Household head: ${_or(head, 'Not specified')}',
        _or(address, 'Address not specified'),
        'Household size: ${_or(householdSize, 'Not specified')}',
        'Assistance: $assistance',
        'Contact: ${_text(contact)}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    head.dispose();
    address.dispose();
    householdSize.dispose();
    contact.dispose();
  }
}

class GenericFormData extends ServiceFormData {
  final TextEditingController details = TextEditingController();
  final TextEditingController contact = TextEditingController();

  /// Collected but not required: this form covers inquiries and item requests,
  /// which a dispatcher can act on without calling anyone back.
  @override
  String? get requiredContactNumber => null;

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        _or(details, 'No details provided'),
        'Contact: ${_or(contact, 'Not specified')}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    details.dispose();
    contact.dispose();
  }
}
