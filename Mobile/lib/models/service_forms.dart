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

  void dispose();
}

String _text(TextEditingController controller) => controller.text.trim();

String _or(TextEditingController controller, String fallback) {
  final value = _text(controller);
  return value.isEmpty ? fallback : value;
}

/// The callback number now comes off the account rather than a field the
/// resident retypes on every request. `phone_number` is required at
/// registration and NOT NULL on `tbl_residents`, so the empty branch is
/// defensive: it can only be reached before the profile has loaded, and the
/// admin panel shows the resident's number beside the request either way.
String _contactLine(String accountNumber) =>
    accountNumber.trim().isEmpty ? 'See resident profile' : accountNumber.trim();

class AmbulanceFormData extends ServiceFormData {
  /// Patient name is never prefilled — the account holder is the likeliest
  /// patient, not the certain one (a head of the family files for the
  /// household), and a name already sitting in the field reads as a default
  /// nobody actually chose.
  AmbulanceFormData({this.contactNumber = ''});

  /// Straight off the account. `tbl_residents.phone_number` is `required` at
  /// registration and NOT NULL, so this is only ever empty if the profile has
  /// not loaded.
  final String contactNumber;

  final TextEditingController patient = TextEditingController();
  final TextEditingController pickup = TextEditingController();
  final TextEditingController destination = TextEditingController();
  final TextEditingController condition = TextEditingController();

  /// Picked via the framework's showDatePicker + showTimePicker
  /// (AmbulanceScheduleField). Null means "as soon as possible" — the
  /// unscheduled request this form has always filed. A plain field, not a
  /// controller: there is no text input for it, and unlike the controllers
  /// above it must survive to `ServiceRequest.scheduledAt` untouched by
  /// [metaLines], never folded into prose.
  DateTime? scheduledAt;

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        'Patient: ${_or(patient, 'Not specified')}',
        '${_or(pickup, 'Address not specified')} → '
            '${_or(destination, 'destination not specified')}',
        'Condition: ${_or(condition, 'Not described')}',
        'Contact: ${_contactLine(contactNumber)}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    patient.dispose();
    pickup.dispose();
    destination.dispose();
    condition.dispose();
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

  /// A road obstruction is reported about a place, not about the reporter, so
  /// this form has never carried a number and still does not.
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
  ReliefFormData({String headName = '', this.contactNumber = ''}) {
    head.text = headName;
  }

  final String contactNumber;

  final TextEditingController head = TextEditingController();
  final TextEditingController address = TextEditingController();
  final TextEditingController householdSize = TextEditingController();
  String assistance = assistanceTypes.first;

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
        'Contact: ${_contactLine(contactNumber)}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    head.dispose();
    address.dispose();
    householdSize.dispose();
  }
}

class GenericFormData extends ServiceFormData {
  GenericFormData({this.contactNumber = ''});

  final String contactNumber;

  final TextEditingController details = TextEditingController();

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        _or(details, 'No details provided'),
        'Contact: ${_contactLine(contactNumber)}',
        'Submitted $submittedLabel',
      ];

  @override
  void dispose() {
    details.dispose();
  }
}
