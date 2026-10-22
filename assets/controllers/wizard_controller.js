import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['step', 'indicator', 'navigation', 'previous', 'next', 'save', 'status'];

  connect() {
    this.originalNoValidate = this.element.noValidate;
    this.element.noValidate = true;
    this.navigationTargets.forEach((element) => { element.hidden = false; });
    const invalid = this.stepTargets.findIndex((step) => step.dataset.stepInvalid === 'true');
    this.show(invalid < 0 ? 0 : invalid, invalid >= 0);
  }

  disconnect() {
    this.element.noValidate = this.originalNoValidate;
  }

  previous() { this.show(this.index - 1); }

  next() {
    if (this.validate(this.stepTargets[this.index])) this.show(this.index + 1);
  }

  submit(event) {
    if (this.index < this.stepTargets.length - 1) {
      event.preventDefault();
      this.next();
      return;
    }
    for (const step of this.stepTargets) {
      if (!this.validate(step)) {
        event.preventDefault();
        return;
      }
    }
  }

  validate(step) {
    const invalid = [...step.querySelectorAll('input, select, textarea')]
      .find((field) => field.willValidate && !field.validity.valid);
    if (!invalid) return true;
    this.show(this.stepTargets.indexOf(step), false);
    invalid.focus();
    invalid.reportValidity();
    return false;
  }

  show(index, focus = true) {
    this.index = Math.max(0, Math.min(index, this.stepTargets.length - 1));
    this.stepTargets.forEach((step, position) => { step.hidden = position !== this.index; });
    this.indicatorTargets.forEach((indicator, position) => {
      if (position === this.index) indicator.setAttribute('aria-current', 'step');
      else indicator.removeAttribute('aria-current');
    });
    this.previousTarget.hidden = this.index === 0;
    this.nextTarget.hidden = this.index === this.stepTargets.length - 1;
    this.saveTarget.hidden = !this.nextTarget.hidden;
    this.statusTarget.textContent = `Étape ${this.index + 1} sur ${this.stepTargets.length}`;
    if (focus) this.stepTargets[this.index].querySelector('legend').focus();
  }
}
