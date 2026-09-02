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

/// The ambulance form's structured fields, as the API takes them.
///
/// A typed object rather than the `Map<String, String>` the multipart body
/// ultimately needs, for the same reason the forms stopped being a map of
/// string keys (M27): a field collected on screen and never sent is invisible
/// in a map and a compile error here. [toFields] is the single place a key
/// name is spelled, and it is the only thing the request builder reads.
///
/// `description` is deliberately absent. The server composes it for an
/// ambulance request from exactly these values
/// (ServiceRequestController::composeAmbulanceDescription) and ignores
/// anything a client sends, so producing one here would be a second composer
/// waiting to disagree with the first.
class AmbulanceIntake {
  const AmbulanceIntake({
    required this.patientName,
    required this.destination,
    this.patientAge,
    this.patientSex,
    this.patientAddress,
    this.patientContactNumber,
    this.pickupLocation,
    this.conditionNotes,
    this.relatives = const [],
  });

  /// The two the server requires for this service; everything else is optional
  /// there too, and admin verification fills the gaps by phone.
  final String patientName;
  final String destination;

  /// Digits only, or null. Never the empty string: `nullable|integer` rejects
  /// `''`, so an untouched field has to be absent from the body rather than
  /// present and blank.
  final String? patientAge;

  /// 'male', 'female', or null — never the dropdown's own "Not specified"
  /// label, which is a display string the API has no rule for.
  final String? patientSex;

  final String? patientAddress;
  final String? patientContactNumber;

  /// Null when the resident left it blank; the server fills it with their
  /// registered barangay rather than the client guessing at it.
  final String? pickupLocation;

  /// `condition_notes` on tbl_service_request, labelled "Medical diagnosis" on
  /// screen. One column, one controller, two names for the same fact.
  final String? conditionNotes;

  final List<String> relatives;

  factory AmbulanceIntake.from(AmbulanceFormData form) {
    String? optional(TextEditingController controller) {
      final value = controller.text.trim();
      return value.isEmpty ? null : value;
    }

    return AmbulanceIntake(
      patientName: _text(form.patient),
      destination: _text(form.destination),
      patientAge: optional(form.age),
      patientSex: form.sexValue,
      patientAddress: optional(form.patientAddress),
      patientContactNumber: optional(form.patientContact),
      pickupLocation: optional(form.pickup),
      conditionNotes: optional(form.diagnosis),
      relatives: form.relativeNames,
    );
  }

  /// The scalar multipart fields, with every null omitted rather than sent
  /// empty. [relatives] is not here: it is a repeated key
  /// (`patient_relatives[]`) and the builder attaches it separately.
  Map<String, String> toFields() => {
        'patient_name': patientName,
        'destination': destination,
        if (patientAge != null) 'patient_age': patientAge!,
        if (patientSex != null) 'patient_sex': patientSex!,
        if (patientAddress != null) 'patient_address': patientAddress!,
        if (patientContactNumber != null)
          'patient_contact_number': patientContactNumber!,
        if (pickupLocation != null) 'pickup_location': pickupLocation!,
        if (conditionNotes != null) 'condition_notes': conditionNotes!,
      };
}

class AmbulanceFormData extends ServiceFormData {
  /// [accountAddress] and [contactNumber] seed the two fields the account can
  /// answer for; both stay fully editable, because the account answers for the
  /// requester and the request is often about someone else.
  ///
  /// The patient's name is deliberately NOT among them. The account holder is
  /// the likeliest patient, not the certain one — a head of the family files
  /// for the household — and a name already sitting in the field is a default
  /// nobody chose, submitted unchecked.
  AmbulanceFormData({this.contactNumber = '', String accountAddress = ''}) {
    patientAddress.text = accountAddress;
    patientContact.text = contactNumber;
  }

  /// Straight off the account. `tbl_residents.phone_number` is `required` at
  /// registration and NOT NULL, so this is only ever empty if the profile has
  /// not loaded.
  final String contactNumber;

  /// The dropdown's own vocabulary. `AppDropdown` takes a non-null value, and
  /// sex is optional server-side (`nullable|in:male,female`), so the unset
  /// state is a real option here rather than a null the widget cannot hold.
  /// [sexValue] maps it back to what the API accepts.
  static const sexUnspecified = 'Not specified';
  static const sexOptions = [sexUnspecified, 'Male', 'Female'];

  final TextEditingController patient = TextEditingController();
  final TextEditingController age = TextEditingController();

  /// Where the patient lives — `patient_address`. Prefilled from the account,
  /// which on this schema is the barangay name and nothing finer
  /// (`tbl_residents` has a `barangay_id` and no street column), so the
  /// resident is expected to add the purok themselves.
  final TextEditingController patientAddress = TextEditingController();

  /// `patient_contact_number` — the number to ring about this patient, which
  /// is not the account's whenever the patient is someone else in the
  /// household. Prefilled with the account number as the common case.
  final TextEditingController patientContact = TextEditingController();

  final TextEditingController pickup = TextEditingController();
  final TextEditingController destination = TextEditingController();

  /// Labelled "Medical diagnosis" on screen and stored in `condition_notes`.
  /// One field, not two: the column has always held exactly this, and adding a
  /// separate `medical_diagnosis` would give the same fact two homes.
  final TextEditingController diagnosis = TextEditingController();

  String sex = sexUnspecified;

  /// Who is travelling with the patient. Starts with one empty slot, the same
  /// as the walk-in dialog's repeater — "Add relative" covers the case that
  /// needs more, and starting at two pads the common trip with a field nobody
  /// fills. Blank slots are dropped when read, not rejected.
  final List<TextEditingController> relatives = [TextEditingController()];

  /// Picked via the framework's showDatePicker + showTimePicker
  /// (AmbulanceScheduleField). Null means "as soon as possible" — the
  /// unscheduled request this form has always filed. A plain field, not a
  /// controller: there is no text input for it, and unlike the controllers
  /// above it must survive to `ServiceRequest.scheduledAt` untouched by
  /// [metaLines], never folded into prose.
  DateTime? scheduledAt;

  /// What the API takes for `patient_sex`: lowercase, or null when unset.
  String? get sexValue => sex == sexUnspecified ? null : sex.toLowerCase();

  /// The relative names actually typed in, in order, blanks removed.
  List<String> get relativeNames => relatives
      .map((controller) => controller.text.trim())
      .where((name) => name.isNotEmpty)
      .toList();

  void addRelative() => relatives.add(TextEditingController());

  /// Never leaves the group empty: a repeater with no rows reads as a broken
  /// section rather than an optional one, and "Add relative" becomes the only
  /// way back to the state the form started in.
  void removeRelative(int index) {
    if (index < 0 || index >= relatives.length) {
      return;
    }
    relatives.removeAt(index).dispose();
    if (relatives.isEmpty) {
      relatives.add(TextEditingController());
    }
  }

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) {
    final names = relativeNames;

    return [
      serviceName,
      'Patient: ${_or(patient, 'Not specified')}',
      'Age: ${_or(age, 'Not specified')}',
      'Sex: ${sexValue ?? 'Not specified'}',
      'Address: ${_or(patientAddress, 'Not specified')}',
      '${_or(pickup, 'Address not specified')} → '
          '${_or(destination, 'destination not specified')}',
      // Deliberately still "Condition:", matching the label the server's own
      // composer writes (ServiceRequestController::composeAmbulanceDescription).
      // These lines are the optimistic row's; the server's text replaces them
      // on the next refresh, and a prefix that differed would make the
      // resident's Track screen visibly rewrite itself for no reason.
      'Condition: ${_or(diagnosis, 'Not described')}',
      if (names.isNotEmpty) 'Relatives: ${names.join(', ')}',
      'Contact: ${_or(patientContact, _contactLine(contactNumber))}',
      'Submitted $submittedLabel',
    ];
  }

  @override
  void dispose() {
    patient.dispose();
    age.dispose();
    patientAddress.dispose();
    patientContact.dispose();
    pickup.dispose();
    destination.dispose();
    diagnosis.dispose();
    for (final controller in relatives) {
      controller.dispose();
    }
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
