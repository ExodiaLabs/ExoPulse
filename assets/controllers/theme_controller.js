import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['label'];

  connect() {
    let theme;
    try { theme = localStorage.getItem('exopulse-theme'); } catch { /* Storage can be disabled by the browser. */ }
    this.apply(theme === 'dark' || theme === 'light' ? theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  }

  toggle() {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    this.apply(theme);
    try { localStorage.setItem('exopulse-theme', theme); } catch { /* The current page still retains the selected theme. */ }
  }

  apply(theme) {
    document.documentElement.dataset.theme = theme;
    this.labelTargets.forEach((label) => { label.textContent = theme === 'dark' ? 'Mode clair' : 'Mode sombre'; });
  }
}
