import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['button', 'panel'];

  connect() {
    this.expanded = false;
    this.desktop = matchMedia('(min-width: 768px)');
    this.onResize = () => {
      const hadFocus = this.panelTarget.contains(document.activeElement);
      const toggleFocused = document.activeElement === this.buttonTarget;
      this.expanded = false;
      this.render();
      if (!this.desktop.matches && hadFocus) this.buttonTarget.focus();
      if (this.desktop.matches && toggleFocused) {
        this.panelTarget.querySelector('a[aria-current="page"], a')?.focus();
      }
    };
    this.desktop.addEventListener('change', this.onResize);
    this.render();
  }

  disconnect() { this.desktop.removeEventListener('change', this.onResize); }

  toggle() {
    this.expanded = !this.expanded;
    this.render();
  }

  close(event) {
    if (this.desktop.matches || !this.expanded) return;
    event.preventDefault();
    this.expanded = false;
    this.render();
    this.buttonTarget.focus();
  }

  render() {
    const visible = this.desktop.matches || this.expanded;
    this.panelTarget.hidden = !visible;
    this.buttonTarget.hidden = this.desktop.matches;
    this.buttonTarget.setAttribute('aria-expanded', String(visible));
  }
}
