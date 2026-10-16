import 'package:flutter/material.dart';

import 'form_inputs.dart' show AppTextField;

/// Validates [formKey]. When a field is invalid it is scrolled to the middle of
/// the screen and focused, so the message is seen without hunting for it, and
/// this returns false.
bool submitForm(GlobalKey<FormState> formKey) {
  final form = formKey.currentState;
  if (form == null) return false;
  if (form.validate()) return true;
  revealFirstError(formKey.currentContext);
  return false;
}

/// Brings the first field under [formContext] that shows an error to the middle
/// of the screen and focuses it: a [Form] field, or an [AppTextField] with an
/// `errorText` (the screens that have no [Form]). Also for errors set from the
/// server: it waits for the frame that draws them.
void revealFirstError(BuildContext? formContext) {
  if (formContext == null) return;
  WidgetsBinding.instance.addPostFrameCallback((_) {
    if (!formContext.mounted) return;
    Element? hit;
    void visit(Element e) {
      if (hit != null) return;
      final state = e is StatefulElement ? e.state : null;
      final widget = e.widget;
      if (widget is AppTextField && widget.errorText != null) {
        hit = e;
        return;
      }
      // forceErrorText reaches hasError a frame late, so it is checked too.
      if (state is FormFieldState && (state.hasError || state.widget.forceErrorText != null)) {
        hit = e;
        return;
      }
      e.visitChildren(visit);
    }

    (formContext as Element).visitChildren(visit);
    if (hit != null) revealField(hit);
  });
}

/// Scrolls the field at [fieldContext] to the middle of the screen and focuses
/// its text input, if it has one. For screens that draw errors with `errorText`
/// instead of a [Form]: give the field a GlobalKey and pass its context.
void revealField(BuildContext? fieldContext) {
  if (fieldContext == null || !fieldContext.mounted) return;
  Scrollable.ensureVisible(
    fieldContext,
    alignment: 0.5,
    duration: const Duration(milliseconds: 250),
    curve: Curves.easeOut,
  );
  _inputFocus(fieldContext as Element)?.requestFocus();
}

FocusNode? _inputFocus(Element root) {
  FocusNode? node;
  void visit(Element e) {
    if (node != null) return;
    if (e is StatefulElement && e.state is EditableTextState) {
      node = (e.state as EditableTextState).widget.focusNode;
      return;
    }
    e.visitChildren(visit);
  }

  root.visitChildren(visit);
  return node;
}
