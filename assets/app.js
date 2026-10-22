import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import FilterController from './controllers/filter_controller.js';
import PollController from './controllers/poll_controller.js';
import ThemeController from './controllers/theme_controller.js';

Turbo.setProgressBarDelay(150);
const application = Application.start();
application.register('theme', ThemeController);

application.register('filter', FilterController);
application.register('poll', PollController);

// Render an already-received error page without replaying the original POST.
document.addEventListener('turbo:frame-missing', (event) => {
  if (event.defaultPrevented) return;
  event.preventDefault();
  event.detail.visit(event.detail.response);
});
