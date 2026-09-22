(function () {
    'use strict';
    var root = document.documentElement;
    root.classList.add('js');
    try {
        if (!window.sessionStorage.getItem('me58_intro_seen_v2')) {
            root.classList.add('intro-pending');
            window.sessionStorage.setItem('me58_intro_seen_v2', '1');
        }
    } catch (error) {
        root.classList.add('intro-pending');
    }
})();
