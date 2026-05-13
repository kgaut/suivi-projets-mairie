import { Controller } from '@hotwired/stimulus';

/**
 * Lanceur d'apps dans le header.
 *
 * Activé par data-controller="app-launcher" sur le wrapper. Cibles :
 * - button : le bouton qui toggle (porte aria-expanded)
 * - menu   : le dropdown caché par défaut
 *
 * Comportement (similaire à user_menu) :
 * - clic sur le bouton  → toggle l'ouverture
 * - clic en dehors      → ferme
 * - touche Escape       → ferme
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        this.boundClose = this.closeIfOutside.bind(this);
        this.boundEscape = this.closeOnEscape.bind(this);
        document.addEventListener('click', this.boundClose);
        document.addEventListener('keydown', this.boundEscape);
    }

    disconnect() {
        document.removeEventListener('click', this.boundClose);
        document.removeEventListener('keydown', this.boundEscape);
    }

    toggle(event) {
        event.stopPropagation();
        const isOpen = this.menuTarget.classList.contains('hidden') === false;
        if (isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.menuTarget.classList.remove('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.menuTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    closeIfOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
            this.buttonTarget.focus();
        }
    }
}
