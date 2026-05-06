import { Controller } from '@hotwired/stimulus';

/**
 * Démo Stimulus côté client — bascule un message au clic.
 *
 * Activé par data-controller="hello" sur un élément ; cibles déclarées
 * via data-hello-target="output". Action déclenchée par
 * data-action="click->hello#toggle".
 */
export default class extends Controller {
    static targets = ['output'];
    static values = { greeting: { type: String, default: 'Hello SPM 👋' } };

    connect() {
        if (this.hasOutputTarget) {
            this.outputTarget.textContent = this.greetingValue;
        }
    }

    toggle() {
        if (!this.hasOutputTarget) {
            return;
        }
        this.outputTarget.textContent =
            this.outputTarget.textContent === this.greetingValue
                ? 'Toggled côté client (Stimulus)'
                : this.greetingValue;
    }
}
