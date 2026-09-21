import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';

import '../models/request_models.dart';
import '../models/service_forms.dart';
import '../state/account_store.dart';

/// What the resident has typed and attached across the service forms.
///
/// The forms used to live in one screen with a dropdown, so switching services
/// and switching back kept the answers. Each service now opens as its own page,
/// and a page is rebuilt on every visit, so the answers live here instead, one
/// level up, for the life of the signed-in session. Backing out of a form and
/// returning to it finds it as it was left.
class ServiceDrafts {
  final AppUser user;

  ServiceDrafts(this.user);

  final Map<ServiceFormKind, ServiceFormData> _forms = {};

  fp.PlatformFile? validId;

  /// Optional. The backend note calls this the road-clearing form's upload, but
  /// it is offered on every service: a blocked driveway matters to an ambulance
  /// dispatch as much as to a clearing crew, and a rule about which forms may
  /// carry a photo is one the resident would have to discover by its absence.
  fp.PlatformFile? sitePhoto;

  /// The request letter of a training or drill (jpg, png or pdf), the upload
  /// that stands in for the valid ID on those services.
  fp.PlatformFile? letter;

  /// Optional free-text companion to the site photo — faster to type than to
  /// stop and photograph.
  final landmark = TextEditingController();

  /// `putIfAbsent`, so the prefill happens once per kind. A resident who
  /// overwrites the name and leaves must not find their own name back in the
  /// field on return.
  ServiceFormData formFor(ServiceFormKind kind) =>
      _forms.putIfAbsent(kind, () => switch (kind) {
            ServiceFormKind.ambulance => AmbulanceFormData(
                contactNumber: user.phone,
                accountName: user.fullName,
                accountFullAddress: user.fullAddress,
              ),
            ServiceFormKind.road => StructuredFormData.road(),
            ServiceFormKind.relief => StructuredFormData.relief(
                headName: user.fullName,
                contactNumber: user.phone,
                accountFullAddress: user.fullAddress,
              ),
            ServiceFormKind.generic => StructuredFormData.generic(contactNumber: user.phone),
            ServiceFormKind.training => StructuredFormData.training(contactNumber: user.phone),
            ServiceFormKind.drill => StructuredFormData.drill(contactNumber: user.phone),
            ServiceFormKind.certification =>
              StructuredFormData.certification(contactNumber: user.phone),
          });

  void dispose() {
    for (final form in _forms.values) {
      form.dispose();
    }
    landmark.dispose();
  }
}
