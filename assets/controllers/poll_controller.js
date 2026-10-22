import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

export default class extends Controller {
  static targets = ['frame', 'state', 'status', 'error', 'refresh'];
  static values = { url: String };

  connect() {
    this.disconnected = false;
    this.inFlight = false;
    this.failed = false;
    this.submissions = new Set();
    this.handledErrors = new WeakSet();
    this.listeners = [
      [this.frameTarget, 'turbo:before-fetch-request', () => this.started()],
      [this.frameTarget, 'turbo:before-fetch-response', (event) => this.response(event)],
      [this.frameTarget, 'turbo:fetch-request-error', (event) => this.requestFailed(event)],
      [this.frameTarget, 'turbo:frame-missing', (event) => this.requestFailed(event)],
      [this.frameTarget, 'turbo:frame-load', () => this.loaded()],
      [document, 'turbo:submit-start', (event) => this.submissions.add(event.target)],
      [document, 'turbo:submit-end', (event) => this.submissions.delete(event.target)],
      [document, 'visibilitychange', () => this.resume()],
      [document, 'turbo:load', () => this.resume()],
      [window, 'online', () => this.resume()],
      [window, 'offline', () => this.resume()],
      [window, 'unhandledrejection', (event) => this.handledRejection(event)],
      [document, 'turbo:before-cache', () => this.beforeCache()],
    ];
    this.listeners.forEach(([target, name, listener]) => target.addEventListener(name, listener));
    this.updateStatus();
    this.schedule();
  }

  disconnect() {
    this.disconnected = true;
    this.clearTimers();
    this.listeners.forEach(([target, name, listener]) => target.removeEventListener(name, listener));
  }

  refresh(event) {
    // Keep the ordinary GET link usable when Turbo is unavailable.
    if (!window.customElements.get('turbo-frame')) return;
    event.preventDefault();
    if (document.hidden || navigator.onLine === false) {
      this.fail('Actualisation impossible hors ligne ou lorsque la page est inactive. Réessayez lorsque la connexion est rétablie.');
      return;
    }
    this.failed = false;
    this.load();
  }

  active() {
    return this.hasStateTarget && this.stateTarget.dataset.refreshActive === 'true';
  }

  paused() {
    return document.hidden || navigator.onLine === false
      || Boolean(document.activeElement?.closest('form'))
      || this.submissions.size > 0 || Boolean(document.querySelector('form[aria-busy="true"]'));
  }

  schedule() {
    clearTimeout(this.timer);
    if (this.disconnected || this.failed || !this.active()) return;
    this.timer = setTimeout(() => {
      if (this.paused() || this.inFlight || this.frameTarget.hasAttribute('busy')) {
        this.schedule();
        return;
      }
      this.load();
    }, 5000);
  }

  load() {
    if (this.disconnected || this.paused() || this.inFlight || this.frameTarget.hasAttribute('busy')) {
      this.schedule();
      return;
    }
    if (!window.customElements.get('turbo-frame')) return;
    this.errorTarget.hidden = true;
    this.started();
    try {
      const url = new URL(this.frameTarget.src || this.urlValue, window.location.href);
      if (url.origin !== window.location.origin) {
        this.fail();
        return;
      }
      // Pagination advances history; refreshing its current URL must only replace it.
      visit(url.href, { frame: this.frameTarget.id, action: 'replace' });
    } catch {
      this.fail();
    }
  }

  started() {
    clearTimeout(this.timer);
    clearTimeout(this.watchdog);
    this.inFlight = true;
    this.failed = false;
    this.errorTarget.hidden = true;
    this.watchdog = setTimeout(() => {
      // Cancel the outstanding frame request before making another refresh possible.
      this.frameTarget.disabled = true;
      this.frameTarget.setAttribute('complete', '');
      this.frameTarget.disabled = false;
      this.fail('Le serveur met trop de temps à répondre. Les derniers résultats affichés sont conservés. Utilisez Actualiser pour réessayer.');
    }, 30000);
  }

  response(event) {
    if (!event.detail.fetchResponse.succeeded) {
      event.preventDefault();
      this.fail();
    }
  }

  requestFailed(event) {
    event.preventDefault();
    if (event.detail?.error instanceof Error) this.handledErrors.add(event.detail.error);
    this.fail();
  }

  handledRejection(event) {
    // Turbo can rethrow a request failure even after its error event was handled.
    // Suppress only that exact object, already reported visibly by this controller.
    if (this.handledErrors.has(event.reason)) event.preventDefault();
  }

  loaded() {
    this.inFlight = false;
    clearTimeout(this.watchdog);
    if (this.disconnected || this.failed) return;
    this.errorTarget.hidden = true;
    this.refreshTarget.href = this.frameTarget.src || window.location.href;
    this.updateStatus();
    this.schedule();
  }

  fail(message = 'L’actualisation a échoué. Les derniers résultats affichés sont conservés. Utilisez Actualiser pour réessayer ou reconnectez-vous si votre session a expiré.') {
    if (this.disconnected) return;
    this.clearTimers();
    this.inFlight = false;
    this.failed = true;
    this.errorTarget.textContent = message;
    this.errorTarget.hidden = false;
    this.statusTarget.textContent = 'Actualisation automatique interrompue.';
  }

  resume() {
    if (navigator.onLine !== false && !document.hidden) this.failed = false;
    this.updateStatus();
    this.schedule();
  }

  updateStatus() {
    this.statusTarget.textContent = navigator.onLine === false
      ? 'Hors ligne : actualisation en pause.'
      : (this.active() ? 'Actualisation toutes les 5 secondes pendant les traitements.' : 'Aucun traitement en cours.');
  }

  clearTimers() {
    clearTimeout(this.timer);
    clearTimeout(this.watchdog);
  }

  beforeCache() {
    this.clearTimers();
    this.frameTarget.removeAttribute('busy');
    this.frameTarget.removeAttribute('aria-busy');
  }
}
