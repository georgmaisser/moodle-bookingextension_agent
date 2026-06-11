// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Global navbar magic-wand entry point for the AI agent.
 *
 * Injected on every page by bookingextension_agent\local\hooks\page_injection
 * when the inject_in_navbar admin setting is enabled, so it MUST stay minimal:
 * no static imports (nothing beyond this tiny module is fetched on page load),
 * no AJAX, no string requests — the button label arrives as a parameter from
 * PHP. Everything heavy (modal, templates, fragment with the aiready panel)
 * is dynamically imported and loaded only on the first click.
 *
 * @module     bookingextension_agent/navbar_magic_wand
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const BUTTON_ID = 'bookingextension-agent-navbar-wand';

let modalPromise = null;

/**
 * Load the agent panel fragment into the modal body.
 *
 * The fragment returns the rendered aiinstructions template plus its
 * collected {{#js}} footer markup; replaceNodeContents executes that JS,
 * which boots aiinstructions.js inside the modal.
 *
 * @param {Object} modal core/modal instance
 * @param {Number} contextid current page context id
 */
const loadPanel = async(modal, contextid) => {
    const Fragment = await import('core/fragment');
    const Templates = await import('core/templates');

    Fragment.loadFragment('bookingextension_agent', 'aipanel', contextid, {contextid: contextid})
        .done((html, js) => {
            Templates.replaceNodeContents(modal.getBody(), html, js);
        })
        .fail(async(ex) => {
            const Notification = await import('core/notification');
            Notification.exception(ex);
        });
};

/**
 * Lazily create the (cached) agent modal. First call pulls in core/modal
 * and the panel fragment; later clicks just re-show the same instance.
 *
 * @param {Number} contextid current page context id
 * @param {String} title modal title (localised, from PHP)
 * @returns {Promise<Object>} resolving to the core/modal instance
 */
const getModal = (contextid, title) => {
    if (!modalPromise) {
        modalPromise = (async() => {
            const Modal = await import('core/modal');
            const modal = await Modal.create({
                title: title,
                body: '<div class="d-flex justify-content-center p-5">'
                    + '<div class="spinner-border" role="status"></div></div>',
                large: true,
            });
            // The preview needs more room than Bootstrap's modal-lg offers.
            // getModal() returns the .modal-dialog itself: modal-xl is the
            // Bootstrap-native baseline (1140px), the hook class widens it
            // further via --bs-modal-width in styles.css.
            modal.getModal().addClass('modal-xl bookingextension-agent-wand-modal');
            loadPanel(modal, contextid);
            return modal;
        })();
    }
    return modalPromise;
};

/**
 * Build the navbar wand element.
 *
 * @param {String} label localised button label
 * @returns {HTMLElement}
 */
const buildButton = (label) => {
    const wrapper = document.createElement('div');
    wrapper.id = BUTTON_ID;
    wrapper.className = 'd-flex align-items-center';

    const link = document.createElement('a');
    link.className = 'nav-link px-2';
    link.href = '#';
    link.setAttribute('role', 'button');
    link.setAttribute('aria-label', label);
    link.setAttribute('title', label);
    link.innerHTML = '<i class="fa fa-magic" aria-hidden="true"></i>';

    wrapper.appendChild(link);
    return wrapper;
};

/**
 * Entry point: inject the wand into the navbar. Pure DOM work, no requests.
 *
 * Targets the Boost user-navigation region and falls back to the generic
 * navbar nav list; if neither exists (exotic theme) it does nothing.
 *
 * @param {Number} contextid current page context id (from the PHP hook)
 * @param {String} label localised button label (from the PHP hook)
 */
export const init = (contextid, label) => {
    if (document.getElementById(BUTTON_ID)) {
        return;
    }

    const host = document.querySelector('#usernavigation')
        || document.querySelector('.navbar .navbar-nav');
    if (!host) {
        return;
    }

    const button = buildButton(label);
    const usermenu = host.querySelector('.usermenu-container, .usermenu');
    if (usermenu && usermenu.parentElement === host) {
        host.insertBefore(button, usermenu);
    } else {
        host.appendChild(button);
    }

    button.addEventListener('click', async(e) => {
        e.preventDefault();
        const modal = await getModal(contextid, label);
        modal.show();
    });
};
