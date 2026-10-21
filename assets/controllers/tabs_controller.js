import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['list', 'tab', 'panel'];

  connect() {
    this.originalNoValidate = this.element.noValidate;
    this.element.noValidate = true;
    this.listTarget.hidden = false;
    this.panelTargets.forEach((panel) => {
      panel.setAttribute('role', 'tabpanel');
      panel.tabIndex = 0;
    });
    const invalid = this.panelTargets.findIndex((panel) => panel.dataset.panelInvalid === 'true');
    this.show(invalid < 0 ? 0 : invalid);
    if (invalid >= 0) this.panelTargets[invalid].focus();
  }

  disconnect() { this.element.noValidate = this.originalNoValidate; }

  select(event) { this.show(this.tabTargets.indexOf(event.currentTarget)); }

  navigate(event) {
    const current = this.tabTargets.indexOf(event.currentTarget);
    const destinations = {
      ArrowRight: (current + 1) % this.tabTargets.length,
      ArrowLeft: (current + this.tabTargets.length - 1) % this.tabTargets.length,
      Home: 0,
      End: this.tabTargets.length - 1,
    };
    if (!(event.key in destinations)) return;
    event.preventDefault();
    this.show(destinations[event.key]);
    this.tabTargets[destinations[event.key]].focus();
  }

  submit(event) {
    const invalid = [...this.element.querySelectorAll('input, select, textarea')]
      .find((field) => field.willValidate && !field.validity.valid);
    if (!invalid) return;
    event.preventDefault();
    const index = this.panelTargets.findIndex((panel) => panel.contains(invalid));
    if (index >= 0) this.show(index);
    invalid.focus();
    invalid.reportValidity();
  }

  show(index) {
    this.tabTargets.forEach((tab, position) => {
      tab.setAttribute('aria-selected', String(position === index));
      tab.tabIndex = position === index ? 0 : -1;
    });
    this.panelTargets.forEach((panel, position) => { panel.hidden = position !== index; });
  }
}
