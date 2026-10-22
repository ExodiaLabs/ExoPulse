import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['status'];

  error(event) {
    // Ignore errors from a later, nested batch.
    if (event.target !== this.element) return;
    event.preventDefault();
    this.statusTarget.textContent = 'Chargement interrompu. Réessayez pour afficher la suite.';
  }

  retry(event) {
    event.preventDefault();
    if (this.element.hasAttribute('busy')) return;
    this.statusTarget.textContent = 'Chargement des constats…';
    this.element.removeAttribute('complete');
    if (this.element.loading === 'lazy') {
      this.element.loading = 'eager';
    } else {
      this.element.reload();
    }
  }
}
