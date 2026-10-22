import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  queue(event) {
    if (!event.isComposing && event.target.matches('input[type="search"], input[name="q"], input[name="type"]')) {
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.element.requestSubmit(), 400);
    }
  }

  change(event) {
    if (event.target.matches('select')) {
      this.cancel();
      this.element.requestSubmit();
    }
  }

  cancel() { clearTimeout(this.timer); }
  disconnect() { this.cancel(); }
}
