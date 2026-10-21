import { Application } from '@hotwired/stimulus';
import ThemeController from './controllers/theme_controller.js';

const application = Application.start();
application.register('theme', ThemeController);
