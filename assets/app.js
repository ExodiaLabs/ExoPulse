import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import LoadMoreController from './controllers/load_more_controller.js';
import FilterController from './controllers/filter_controller.js';
import NavigationController from './controllers/navigation_controller.js';
import PollController from './controllers/poll_controller.js';
import TabsController from './controllers/tabs_controller.js';
import ThemeController from './controllers/theme_controller.js';
import WizardController from './controllers/wizard_controller.js';

Turbo.setProgressBarDelay(150);
const application = Application.start();
application.register('theme', ThemeController);

application.register('load-more', LoadMoreController);
application.register('filter', FilterController);
application.register('poll', PollController);
application.register('wizard', WizardController);
application.register('tabs', TabsController);
application.register('navigation', NavigationController);

// Render an already-received error page without replaying the original POST.
document.addEventListener('turbo:frame-missing', (event) => {
  if (event.defaultPrevented) return;
  event.preventDefault();
  event.detail.visit(event.detail.response);
});
