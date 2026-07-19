(function () {
    'use strict';

    var modal;
    var messageElement;
    var closeButton;
    var lastFocusedElement;

    function findModal() {
        if (modal && modal.isConnected) {
            return true;
        }

        modal = document.getElementById('message-modal');
        messageElement = document.getElementById('message-modal-content');
        closeButton = modal && modal.querySelector('[data-message-modal-close]');

        return Boolean(modal && messageElement && closeButton);
    }

    function isOpen() {
        return findModal() && !modal.hidden;
    }

    function focusableElements() {
        return modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    }

    function closeMessageModal(restoreFocus) {
        if (!isOpen()) {
            return;
        }

        modal.classList.remove('is-active');
        modal.setAttribute('aria-hidden', 'true');
        modal.hidden = true;
        messageElement.textContent = '';

        var trigger = lastFocusedElement;
        lastFocusedElement = null;
        if (restoreFocus !== false && trigger && trigger.isConnected && typeof trigger.focus === 'function') {
            trigger.focus();
        }
    }

    function openMessageModal(message, trigger) {
        if (!findModal()) {
            return;
        }

        lastFocusedElement = trigger && trigger.isConnected ? trigger : document.activeElement;
        messageElement.textContent = message === null || message === undefined ? '' : String(message);
        modal.hidden = false;
        modal.classList.add('is-active');
        modal.setAttribute('aria-hidden', 'false');

        window.requestAnimationFrame(function () {
            if (isOpen()) {
                closeButton.focus();
            }
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var closeControl = target && target.closest('[data-message-modal-close]');

        if (closeControl && isOpen() && modal.contains(closeControl)) {
            closeMessageModal();
        }
    });

    document.body.addEventListener('htmx:beforeSwap', function (event) {
        var target = event.detail && event.detail.target;
        if (target instanceof Element && target.id === 'main-section') {
            closeMessageModal(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!isOpen()) {
            return;
        }

        if (event.key === 'Escape' || event.keyCode === 27) {
            event.preventDefault();
            closeMessageModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        var elements = focusableElements();
        var first = elements[0];
        var last = elements[elements.length - 1];

        if (!first || !last) {
            event.preventDefault();
            return;
        }

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    window.mataOpenMessageModal = openMessageModal;
    window.mataCloseMessageModal = closeMessageModal;
}());
