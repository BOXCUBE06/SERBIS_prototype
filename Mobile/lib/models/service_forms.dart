import 'package:flutter/widgets.dart';

/// The four guided request forms: [AmbulanceFormData], and the road, relief
/// and generic forms, which are one [StructuredFormData] over three field
/// lists.
///
/// These used to be a `Map<String, TextEditingController>` keyed by strings
/// like `'amb_patient'`, filled by `_ctrl('amb_patient')` on the way in and
/// read by `_text('amb_patient')` on the way out. Nothing connected the two:
/// `_ctrl` creates a controller on demand, so a field could be collected and
/// never read and the analyzer had nothing to say about it. That is exactly
/// what M2 was — five fields typed in by residents and dropped before the
/// request was sent.
///
/// Here every field is declared once and carries its own output line — a named
/// property on the ambulance form, a [ServiceFormField] on the other three. A
/// field that is rendered but left out of the description is visible in one
/// screenful of code either way, and a rename that misses one side does not
/// compile.
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

const _programMonths = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/// "5 Oct 2026". English on purpose, like every other line of the description:
/// it is read by an admin, not shown back to the resident.
String formatProgramDate(DateTime date) =>
    '${date.day} ${_programMonths[date.month - 1]} ${date.year}';

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
      patientAddress: AmbulanceFormData.composeAddress(form.patientBarangay, form.patientAddress),
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
        if (patientAddress != null) 'patient_address': patientAddress!,
        if (patientContactNumber != null)
          'patient_contact_number': patientContactNumber!,
        if (pickupLocation != null) 'pickup_location': pickupLocation!,
        if (conditionNotes != null) 'condition_notes': conditionNotes!,
      };
}

class AmbulanceFormData extends ServiceFormData {
  /// [contactNumber] seeds the one field the account can answer for
  /// unconditionally; it stays fully editable, because the account answers
  /// for the requester and the request is often about someone else.
  ///
  /// The patient's name and the two address fields are deliberately NOT
  /// prefilled the same way. The account holder is the likeliest patient and
  /// the likeliest address, not the certain one — a head of the family files
  /// for the household, and a request is often about someone or somewhere
  /// else — so a value already sitting in the field is a default nobody
  /// chose, submitted unchecked. [accountName] and [accountFullAddress] are
  /// kept only for the three "same as mine" setters below, which fill on an
  /// explicit, unchecked-by-default confirmation instead (MDRRMO feedback,
  /// 2026-09-19).
  AmbulanceFormData({
    this.contactNumber = '',
    this.accountName = '',
    this.accountFullAddress = '',
    this.accountBarangay = '',
    this.accountStreet = '',
  }) {
    patientContact.text = contactNumber;
  }

  /// Straight off the account. `tbl_residents.phone_number` is `required` at
  /// registration and NOT NULL, so this is only ever empty if the profile has
  /// not loaded.
  final String contactNumber;

  /// The account holder's name, for [setPatientIsAccountHolder] to copy into
  /// [patient] — never written to the field on its own.
  final String accountName;

  /// The account holder's purok/street and barangay together
  /// (`AppUser.fullAddress`), for [setPatientAddressIsMyAddress] and
  /// [setPickupIsMyAddress] to copy from — never written to either field on
  /// its own.
  final String accountFullAddress;

  /// The account's barangay name and purok/street, for "Same as my address"
  /// to select and fill separately.
  final String accountBarangay;
  final String accountStreet;

  /// The barangay dropdowns' free-text choice: the field below then holds the
  /// whole address instead of a purok/street.
  static const barangayOther = 'Other';

  /// Patient's barangay: a name, [barangayOther], or null when none is picked yet.
  String? patientBarangay;

  /// "purok/street, barangay", or the field's text alone when no barangay is
  /// picked or it is [barangayOther]. Null when there is nothing to send.
  static String? composeAddress(String? barangay, TextEditingController field) {
    final text = field.text.trim();
    final parts = [
      text,
      if (barangay != null && barangay != barangayOther) barangay,
    ].where((part) => part.isNotEmpty);
    return parts.isEmpty ? null : parts.join(', ');
  }

  /// Fills [field] from the account and returns the barangay to select: the
  /// account's own when [barangays] has it, else [barangayOther] with the full
  /// address as free text.
  String _fillMyAddress(TextEditingController field, List<String> barangays) {
    if (barangays.contains(accountBarangay)) {
      field.text = accountStreet;
      return accountBarangay;
    }
    field.text = accountFullAddress;
    return barangayOther;
  }

  /// Whether the "Patient is myself" checkbox is ticked. Read by the screen
  /// to draw the checkbox's own state; setting [patient] happens in
  /// [setPatientIsAccountHolder], not here, so this stays a plain flag.
  bool patientIsAccountHolder = false;

  /// Fills [patient] from the account holder's name when [value] is true.
  /// Unchecking afterward does not clear the field — the name stays fully
  /// editable either way, the same as every other prefilled field on this
  /// form.
  void setPatientIsAccountHolder(bool value) {
    patientIsAccountHolder = value;
    if (value) {
      patient.text = accountName;
    }
  }

  /// Whether the "Same as my address" checkbox next to [patientAddress] is
  /// ticked.
  bool patientAddressIsMyAddress = false;

  /// Fills [patientAddress] from the account's full address when [value] is
  /// true. Same shape as [setPatientIsAccountHolder]: unchecking does not
  /// clear whatever is now in the field.
  void setPatientAddressIsMyAddress(bool value, [List<String> barangays = const []]) {
    patientAddressIsMyAddress = value;
    if (value) {
      patientBarangay = _fillMyAddress(patientAddress, barangays);
    }
  }

  /// Whether the "Same as my address" checkbox next to [pickup] is ticked.
  /// Separate from [patientAddressIsMyAddress] — the pickup point and the
  /// patient's own address are often the same, but a request filed for
  /// someone at a different location must be able to say so independently.
  bool pickupIsMyAddress = false;

  /// Selects [pickupOther] and fills the account's full address.
  void setPickupIsMyAddress(bool value) {
    pickupIsMyAddress = value;
    if (value) {
      pickupChoice = pickupOther;
      pickup.text = accountFullAddress;
    }
  }

  /// From's free-text choice, beside the hospital list To also uses.
  static const pickupOther = 'Other';

  /// From's selection: a hospital name or [pickupOther]. Same shape as
  /// [destinationChoice]: picking a hospital copies it into [pickup].
  String pickupChoice = pickupOther;

  void setPickupChoice(String value) {
    pickupChoice = value;
    if (value != pickupOther) {
      pickup.text = value;
    }
  }

  final TextEditingController patient = TextEditingController();
  final TextEditingController age = TextEditingController();

  /// Where the patient lives — `patient_address`. See
  /// [setPatientAddressIsMyAddress] for how the account's own address reaches
  /// this field.
  final TextEditingController patientAddress = TextEditingController();

  /// `patient_contact_number` — the number to ring about this patient, which
  /// is not the account's whenever the patient is someone else in the
  /// household. Prefilled with the account number as the common case.
  final TextEditingController patientContact = TextEditingController();

  /// See [setPickupIsMyAddress] for how the account's own address reaches
  /// this field.
  final TextEditingController pickup = TextEditingController();

  /// The value actually sent as `destination` — free text either way. See
  /// [destinationChoice]/[setDestinationChoice] for how the dropdown feeds
  /// it (MDRRMO feedback, 2026-09-19).
  final TextEditingController destination = TextEditingController();

  /// The sentinel [destinationChoice] holds when the resident is typing
  /// their own destination rather than picking a seeded one. A value, not
  /// null, so it can sit directly in the dropdown's own item list.
  static const destinationOthers = 'Others';

  /// The destination dropdown's own selection — a seeded name, or
  /// [destinationOthers] for free text. Starts on [destinationOthers]: there
  /// is no default destination to assume, the same reasoning behind every
  /// other field on this form that isn't prefilled.
  String destinationChoice = destinationOthers;

  /// Picking a seeded destination copies it into [destination] — the field
  /// the request actually sends — so the dropdown is a shortcut onto the
  /// same free-text column, not a second source of truth. Picking
  /// [destinationOthers] leaves whatever is already typed alone, the same
  /// "unchecking doesn't clear" shape as the checkboxes above.
  void setDestinationChoice(String value) {
    destinationChoice = value;
    if (value != destinationOthers) {
      destination.text = value;
    }
  }

  /// Labelled "Medical diagnosis" on screen and stored in `condition_notes`.
  /// One field, not two: the column has always held exactly this, and adding a
  /// separate `medical_diagnosis` would give the same fact two homes.
  final TextEditingController diagnosis = TextEditingController();

  /// Companions travelling with the patient: at least one is required and two
  /// is the cap, per MDRRMO policy (the hospital asks for a companion), and the
  /// server enforces both. Starts with one empty slot, the same as the walk-in
  /// dialog's repeater. Blank slots are dropped when read, not rejected — a
  /// form whose only slot is blank simply names nobody.
  static const maxRelatives = 2;

  final List<TextEditingController> relatives = [TextEditingController()];

  /// Picked via the framework's showDatePicker + showTimePicker
  /// (AmbulanceScheduleField). Null means "as soon as possible" — the
  /// unscheduled request this form has always filed. A plain field, not a
  /// controller: there is no text input for it, and unlike the controllers
  /// above it must survive to `ServiceRequest.scheduledAt` untouched by
  /// [metaLines], never folded into prose.
  DateTime? scheduledAt;

  /// Which of the six form sections hold an answer, for the progress bar:
  /// patient, trip, condition, relatives, when (ASAP is an answer), valid ID.
  List<bool> sectionsDone({required bool hasValidId}) => [
        patient.text.trim().isNotEmpty,
        destination.text.trim().isNotEmpty,
        diagnosis.text.trim().isNotEmpty,
        relativeNames.isNotEmpty,
        true,
        hasValidId,
      ];

  /// Fires when a field [sectionsDone] reads changes.
  Listenable get progressListenable => Listenable.merge([patient, destination, diagnosis, ...relatives]);

  /// The relative names actually typed in, in order, blanks removed.
  List<String> get relativeNames => relatives
      .map((controller) => controller.text.trim())
      .where((name) => name.isNotEmpty)
      .toList();

  void addRelative() {
    if (relatives.length >= maxRelatives) return;
    relatives.add(TextEditingController());
  }

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

/// One input on a structured form, together with the line it contributes to
/// the description.
///
/// Both halves are declared in one place on purpose. The whole reason the
/// forms stopped being a map of string keys (M27) was that a field could be
/// rendered, typed into and never read on the way out with nothing to flag it;
/// a field that carries its own output line cannot be collected and dropped,
/// because there is no second list for it to fall out of.
class ServiceFormField {
  const ServiceFormField.text({
    required this.key,
    required this.label,
    required this.hint,
    required this.metaFallback,
    this.lines = 1,
    this.keyboard = TextInputType.text,
    this.metaPrefix,
    this.helpText,
  })  : options = const [],
        isDate = false,
        minDaysAhead = 0;

  /// A closed list, so it always has an answer — there is no fallback because
  /// there is no blank state to fall back from.
  const ServiceFormField.choice({
    required this.key,
    required this.label,
    required this.options,
    required this.metaPrefix,
  })  : hint = '',
        lines = 1,
        keyboard = TextInputType.text,
        metaFallback = '',
        helpText = null,
        isDate = false,
        minDaysAhead = 0;

  /// A day picked from a calendar, at least [minDaysAhead] days from today.
  /// Held as a [DateTime], not typed, so it can be sent as a real field and
  /// checked against the same lead time the server enforces.
  const ServiceFormField.date({
    required this.key,
    required this.label,
    required this.metaPrefix,
    required this.metaFallback,
    this.minDaysAhead = 0,
  })  : hint = '',
        lines = 1,
        keyboard = TextInputType.text,
        options = const [],
        helpText = null,
        isDate = true;

  /// Stable id, and the key the controller is stored under — so a renamed
  /// field breaks in one place rather than drifting apart between the widget
  /// and the description.
  final String key;

  final String label;
  final String hint;
  final int lines;
  final TextInputType keyboard;

  /// Empty for a free-text field, the choices for a dropdown.
  final List<String> options;

  /// e.g. `'Household size: '`. Null for the fields a dispatcher reads bare,
  /// like the relief address and the generic details.
  final String? metaPrefix;

  /// Shown behind a "?" tooltip next to the label. Null for every field
  /// except household_size, whose count is the one ambiguous enough to ask
  /// about (MDRRMO feedback, 2026-09-14).
  final String? helpText;

  /// What the line says when the resident left the field empty. Never blank: a
  /// gap in the block reads as a field that was never asked for.
  final String metaFallback;

  /// True for a calendar day; see [ServiceFormField.date].
  final bool isDate;

  /// Only meaningful when [isDate].
  final int minDaysAhead;

  bool get isChoice => options.isNotEmpty;
}

/// A labelled group of fields, matching one `FormSection` on screen.
class ServiceFormSection {
  const ServiceFormSection({required this.labelKey, required this.fields});

  /// A `translations.dart` key. The section headings are the one part of these
  /// forms the resident reads in their own language.
  final String labelKey;

  final List<ServiceFormField> fields;
}

/// Which uploads a service asks for. Every response service asks for a photo
/// of a valid ID and offers a site photo; the MDRRMO programs are requested by
/// a barangay or an organization, which has no ID, and ask for a request
/// letter instead.
enum ServiceAttachments { standard, letterRequired, letterOptional }

/// Days between filing and the earliest date a scheduled program can be asked
/// for. Mirrors the server's own `PROGRAM_LEAD_DAYS`; duplicated rather than
/// fetched because it is a fixed policy, and catching it here saves the round
/// trip.
const kProgramLeadDays = 14;

/// What one service's form is made of.
class ServiceFormSpec {
  const ServiceFormSpec({
    required this.sections,
    required this.carriesContact,
    this.attachments = ServiceAttachments.standard,
  });

  final ServiceAttachments attachments;

  final List<ServiceFormSection> sections;

  /// Whether the description ends with the account's callback number. False
  /// for a road report, which is about a place and not about the reporter.
  final bool carriesContact;

  Iterable<ServiceFormField> get fields =>
      sections.expand((section) => section.fields);
}

const kObstructionTypes = [
  'Fallen tree / branches',
  'Flooding / silt',
  'Landslide debris',
  'Other',
];

const kAssistanceTypes = [
  'Food packs',
  'Hygiene kits',
  'Drinking water',
  'Temporary shelter materials',
  'Other',
];

const _roadSpec = ServiceFormSpec(
  carriesContact: false,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.location',
      fields: [
        ServiceFormField.text(
          key: 'location',
          label: 'Location / road name',
          hint: 'e.g. Brgy. Malasin – Provincial Road',
          metaFallback: 'Location not specified',
        ),
        ServiceFormField.choice(
          key: 'obstruction',
          label: 'Obstruction type',
          options: kObstructionTypes,
          metaPrefix: 'Obstruction: ',
        ),
      ],
    ),
    ServiceFormSection(
      labelKey: 'form_section.description',
      fields: [
        ServiceFormField.text(
          key: 'description',
          label: 'Description',
          hint: "Describe the obstruction and how it's affecting access",
          lines: 3,
          metaPrefix: 'Description: ',
          metaFallback: 'No description provided',
        ),
      ],
    ),
  ],
);

const _reliefSpec = ServiceFormSpec(
  carriesContact: true,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.household',
      fields: [
        ServiceFormField.text(
          key: 'household_head',
          label: 'Household head name',
          hint: 'e.g. Juan Dela Cruz',
          metaPrefix: 'Household head: ',
          metaFallback: 'Not specified',
        ),
        ServiceFormField.text(
          key: 'address',
          label: 'Address',
          hint: 'Purok / street, barangay',
          metaFallback: 'Address not specified',
        ),
        // The number that decides how many food packs are loaded. Its own
        // field with its own line out, rather than something an operator has
        // to find inside a sentence — see service_forms_shape_test.dart.
        ServiceFormField.text(
          key: 'household_size',
          label: 'Household size',
          hint: 'e.g. 5',
          keyboard: TextInputType.number,
          metaPrefix: 'Household size: ',
          metaFallback: 'Not specified',
          helpText: 'Count everyone who regularly eats and sleeps in this '
              'household, including yourself.',
        ),
      ],
    ),
    ServiceFormSection(
      labelKey: 'form_section.assistance',
      fields: [
        ServiceFormField.choice(
          key: 'assistance',
          label: 'Type of assistance needed',
          options: kAssistanceTypes,
          metaPrefix: 'Assistance: ',
        ),
      ],
    ),
  ],
);

/// DRRM Trainings and Seminars (IEC). Everything except the date goes into the
/// description like the other forms; the date is a real field so the server can
/// enforce the lead time.
const kDrillTypes = [
  'Earthquake (NSED)',
  'Fire',
  'Flood',
  'Other',
];

const _trainingSpec = ServiceFormSpec(
  carriesContact: true,
  attachments: ServiceAttachments.letterRequired,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.event',
      fields: [
        ServiceFormField.date(
          key: 'preferred_date',
          label: 'Preferred date',
          metaPrefix: 'Preferred date: ',
          metaFallback: 'Not specified',
          minDaysAhead: kProgramLeadDays,
        ),
        ServiceFormField.text(
          key: 'location',
          label: 'Location',
          hint: 'Venue, purok, barangay',
          metaPrefix: 'Location: ',
          metaFallback: 'Location not specified',
        ),
        ServiceFormField.text(
          key: 'participants',
          label: 'Expected number of participants',
          hint: 'e.g. 40',
          keyboard: TextInputType.number,
          metaPrefix: 'Participants: ',
          metaFallback: 'Not specified',
        ),
        ServiceFormField.text(
          key: 'topic',
          label: 'Training topic',
          hint: 'e.g. Basic life support, fire safety',
          metaPrefix: 'Topic: ',
          metaFallback: 'Not specified',
        ),
      ],
    ),
  ],
);

/// Simulation Drills / NSED. Same shape as the training form with a drill type
/// where the topic was, and the same 14 day lead time and request letter.
const _drillSpec = ServiceFormSpec(
  carriesContact: true,
  attachments: ServiceAttachments.letterRequired,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.event',
      fields: [
        ServiceFormField.date(
          key: 'preferred_date',
          label: 'Preferred date',
          metaPrefix: 'Preferred date: ',
          metaFallback: 'Not specified',
          minDaysAhead: kProgramLeadDays,
        ),
        ServiceFormField.text(
          key: 'location',
          label: 'Location',
          hint: 'Venue, purok, barangay',
          metaPrefix: 'Location: ',
          metaFallback: 'Location not specified',
        ),
        ServiceFormField.choice(
          key: 'drill_type',
          label: 'Drill type',
          options: kDrillTypes,
          metaPrefix: 'Drill type: ',
        ),
        ServiceFormField.text(
          key: 'participants',
          label: 'Expected number of participants',
          hint: 'e.g. 40',
          keyboard: TextInputType.number,
          metaPrefix: 'Participants: ',
          metaFallback: 'Not specified',
        ),
      ],
    ),
  ],
);

/// MDRRMO Certification. No date and no scheduling: the resident says what the
/// certificate is for and which one they need, and may attach a supporting
/// document. The certification type is free text because the office has not
/// given a fixed list.
const _certificationSpec = ServiceFormSpec(
  carriesContact: true,
  attachments: ServiceAttachments.letterOptional,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.certification',
      fields: [
        ServiceFormField.text(
          key: 'certification_type',
          label: 'Certification type',
          hint: 'Which certificate do you need?',
          metaPrefix: 'Certification type: ',
          metaFallback: 'Not specified',
        ),
        ServiceFormField.text(
          key: 'purpose',
          label: 'Purpose',
          hint: 'What is the certificate for?',
          lines: 3,
          metaPrefix: 'Purpose: ',
          metaFallback: 'Not specified',
        ),
      ],
    ),
  ],
);

const _genericSpec = ServiceFormSpec(
  carriesContact: true,
  sections: [
    ServiceFormSection(
      labelKey: 'form_section.details',
      fields: [
        ServiceFormField.text(
          key: 'details',
          label: 'Details',
          hint: 'Describe what you need and where',
          lines: 4,
          metaFallback: 'No details provided',
        ),
      ],
    ),
  ],
);

/// The road, relief and generic forms, which were three classes differing only
/// in their field list.
///
/// Each carried its own controllers, its own `metaLines` and its own
/// `dispose`, and `ServiceFormFields` carried a near-identical `Column` for
/// each — so adding a field meant editing two files in three places, and
/// nothing told you when you had edited only one of them.
///
/// The collapse is of the scaffolding only. Every field the three forms
/// collected is still a field, and `metaLines` emits the same block of text
/// character for character: `service_forms_shape_test.dart` pins all six
/// filled-and-blank cases and was written and run against the three separate
/// classes before they were merged. The ambulance form stays its own class —
/// its fields go to real columns on `tbl_service_request` through
/// [AmbulanceIntakeFields], not into the description at all.
final class StructuredFormData extends ServiceFormData {
  StructuredFormData._(
    this.spec, {
    this.contactNumber = '',
    this.offersFulfillment = false,
    this.accountFullAddress = '',
    Map<String, String> prefill = const {},
  }) : hasAddressField = spec.fields.any((field) => field.key == 'address') {
    for (final field in spec.fields) {
      if (field.isDate) {
        _dates[field.key] = null;
      } else if (field.isChoice) {
        _choices[field.key] = field.options.first;
      } else {
        _controllers[field.key] =
            TextEditingController(text: prefill[field.key] ?? '');
      }
    }
  }

  static StructuredFormData road() => StructuredFormData._(_roadSpec);

  /// The household head is the account holder by definition — the app is
  /// distributed one account per household — so the name is prefilled for the
  /// same reason the patient name is, with more confidence. The location
  /// [address] itself is not prefilled the same way — see
  /// [setAddressIsMyAddress] — because relief goods are often requested for
  /// somewhere other than the account holder's own address.
  static StructuredFormData relief({
    String headName = '',
    String contactNumber = '',
    String accountFullAddress = '',
  }) =>
      StructuredFormData._(
        _reliefSpec,
        contactNumber: contactNumber,
        offersFulfillment: true,
        accountFullAddress: accountFullAddress,
        prefill: {'household_head': headName},
      );

  static StructuredFormData generic({String contactNumber = ''}) =>
      StructuredFormData._(_genericSpec, contactNumber: contactNumber);

  static StructuredFormData training({String contactNumber = ''}) =>
      StructuredFormData._(_trainingSpec, contactNumber: contactNumber);

  static StructuredFormData drill({String contactNumber = ''}) =>
      StructuredFormData._(_drillSpec, contactNumber: contactNumber);

  static StructuredFormData certification({String contactNumber = ''}) =>
      StructuredFormData._(_certificationSpec, contactNumber: contactNumber);

  final ServiceFormSpec spec;
  final String contactNumber;

  /// True only for [relief] — pickup/delivery beyond equipment borrowing
  /// (MDRRMO feedback, 2026-09-18). Ambulance and conduction don't map onto
  /// this at all, and road/generic weren't asked for it, so this stays a
  /// per-instance flag rather than something every StructuredFormData shows.
  final bool offersFulfillment;

  /// The account holder's purok/street and barangay together
  /// (`AppUser.fullAddress`), for [setAddressIsMyAddress] to copy from — only
  /// ever non-empty when [hasAddressField] is true (relief).
  final String accountFullAddress;

  /// True when this form has a field keyed `'address'` — relief only, road
  /// and generic don't collect a location the same way. Drives whether the
  /// "Same as my address" checkbox is shown at all.
  final bool hasAddressField;

  /// Whether the "Same as my address" checkbox next to the address field is
  /// ticked. Meaningless when [hasAddressField] is false.
  bool addressIsMyAddress = false;

  /// Fills the `'address'` field from the account's full address when
  /// [value] is true. Same shape as AmbulanceFormData's "same as mine"
  /// setters: unchecking does not clear whatever is now in the field.
  void setAddressIsMyAddress(bool value) {
    addressIsMyAddress = value;
    if (value) {
      field('address').text = accountFullAddress;
    }
  }

  /// 'Pickup' or 'Delivery' — sent as its own field, not folded into
  /// [metaLines]/description, the same way AmbulanceFormData.scheduledAt
  /// isn't: this is a real column on tbl_service_request
  /// (fulfillment_method), not prose for a dispatcher to re-parse.
  String fulfillmentMethod = 'Pickup';

  /// Meaningful only when [fulfillmentMethod] is 'Delivery' — dropped on the
  /// way out otherwise, same as the server does, so an address typed in and
  /// then switched back to Pickup cannot survive as a delivery instruction.
  final TextEditingController deliveryAddress = TextEditingController();

  final Map<String, TextEditingController> _controllers = {};
  final Map<String, String> _choices = {};
  final Map<String, DateTime?> _dates = {};

  /// The day picked on a [ServiceFormField.date] field, or null before one is.
  DateTime? date(String key) {
    if (!_dates.containsKey(key)) {
      throw ArgumentError.value(key, 'key', 'not a date field on this form');
    }
    return _dates[key];
  }

  void setDate(String key, DateTime? value) {
    date(key);
    _dates[key] = value == null ? null : DateTime(value.year, value.month, value.day);
  }

  /// What goes out as `preferred_date`. Null on every form without one.
  DateTime? get preferredDate => _dates['preferred_date'];

  /// Throws rather than creating one on demand, which is the failure the old
  /// `_ctrl(key)` map had: a typo produced an empty controller that rendered
  /// and submitted nothing, and nothing anywhere said so.
  TextEditingController field(String key) {
    final controller = _controllers[key];
    if (controller == null) {
      throw ArgumentError.value(key, 'key', 'not a text field on this form');
    }
    return controller;
  }

  String choice(String key) {
    final value = _choices[key];
    if (value == null) {
      throw ArgumentError.value(key, 'key', 'not a dropdown on this form');
    }
    return value;
  }

  void select(String key, String value) {
    choice(key);
    _choices[key] = value;
  }

  @override
  List<String> metaLines({
    required String serviceName,
    required String submittedLabel,
  }) =>
      [
        serviceName,
        for (final field in spec.fields) _lineFor(field),
        if (spec.carriesContact) 'Contact: ${_contactLine(contactNumber)}',
        'Submitted $submittedLabel',
      ];

  String _lineFor(ServiceFormField field) {
    final String value;
    if (field.isDate) {
      final picked = _dates[field.key];
      value = picked == null ? field.metaFallback : formatProgramDate(picked);
    } else if (field.isChoice) {
      value = _choices[field.key]!;
    } else {
      value = _or(_controllers[field.key]!, field.metaFallback);
    }

    return field.metaPrefix == null ? value : '${field.metaPrefix}$value';
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    deliveryAddress.dispose();
  }
}
