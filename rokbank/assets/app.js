(function () {
    'use strict';

    var root = document.documentElement;

    function initIntro() {
        var splash = document.querySelector('[data-intro-splash]');
        if (!root.classList.contains('intro-pending') || !splash) return;
        document.body.classList.add('intro-locked');
        window.setTimeout(function () {
            root.classList.add('intro-revealing');
        }, 2000);
        window.setTimeout(function () {
            root.classList.remove('intro-pending', 'intro-revealing');
            document.body.classList.remove('intro-locked');
            splash.remove();
        }, 2450);
    }

    function normalizeText(value) {
        var text = String(value || '').trim().toLocaleLowerCase();
        try { return text.normalize('NFKC'); } catch (error) { return text; }
    }

    function roundedRectangle(context, x, y, width, height, radius) {
        var safeRadius = Math.min(radius, width / 2, height / 2);
        if (typeof context.roundRect === 'function') {
            context.roundRect(x, y, width, height, safeRadius);
            return;
        }
        context.moveTo(x + safeRadius, y);
        context.lineTo(x + width - safeRadius, y);
        context.quadraticCurveTo(x + width, y, x + width, y + safeRadius);
        context.lineTo(x + width, y + height - safeRadius);
        context.quadraticCurveTo(x + width, y + height, x + width - safeRadius, y + height);
        context.lineTo(x + safeRadius, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - safeRadius);
        context.lineTo(x, y + safeRadius);
        context.quadraticCurveTo(x, y, x + safeRadius, y);
    }

    function initPasswordButtons() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-toggle-password'));
                if (!input) return;
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.textContent = showing ? 'Ver' : 'Ocultar';
                button.setAttribute('aria-label', showing ? 'Mostrar contraseña' : 'Ocultar contraseña');
            });
        });
    }

    function initConfirmations() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm') || '¿Confirmar esta acción?')) {
                    event.preventDefault();
                }
            });
        });
    }

    function initFlashMessages() {
        document.querySelectorAll('[data-dismiss-flash]').forEach(function (button) {
            button.addEventListener('click', function () {
                var flash = button.closest('.flash');
                if (flash) flash.remove();
            });
        });
    }

    function initPlayerPickers() {
        document.querySelectorAll('[data-player-picker]').forEach(function (picker) {
            var select = picker.querySelector('[data-existing-player]');
            var input = picker.querySelector('[data-new-player-name]');
            if (!select || !input) return;
            select.addEventListener('change', function () {
                if (select.value) input.value = '';
            });
            input.addEventListener('input', function () {
                if (input.value.trim()) select.value = '';
            });
        });
    }

    function initBatchCounter() {
        document.querySelectorAll('[data-batch-text]').forEach(function (textarea) {
            var counter = textarea.closest('label') ? textarea.closest('label').querySelector('[data-batch-lines]') : null;
            if (!counter) return;
            var update = function () {
                counter.textContent = String(textarea.value.split(/\r?\n/).filter(function (line) { return line.trim() !== ''; }).length);
            };
            textarea.addEventListener('input', update);
            update();
        });
    }

    function initPlayerSearch() {
        var input = document.querySelector('[data-player-search]');
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-player-card]'));
        if (!input || cards.length === 0) return;
        var clear = document.querySelector('[data-clear-search]');
        var empty = document.querySelector('[data-search-empty]');
        var count = document.querySelector('[data-visible-player-count]');
        var filters = Array.prototype.slice.call(document.querySelectorAll('[data-player-filter]'));
        var activeFilter = 'all';

        function applyFilters() {
            var query = normalizeText(input.value);
            var visible = 0;
            cards.forEach(function (card) {
                var nameMatch = !query || normalizeText(card.getAttribute('data-player-name')).indexOf(query) !== -1;
                var status = card.getAttribute('data-player-status');
                var statusMatch = activeFilter === 'all' || status === activeFilter;
                card.hidden = !(nameMatch && statusMatch);
                if (!card.hidden) visible++;
            });
            if (clear) clear.hidden = input.value.length === 0;
            if (empty) empty.hidden = visible !== 0;
            if (count) count.textContent = String(visible);
        }

        input.addEventListener('input', applyFilters);
        if (clear) {
            clear.addEventListener('click', function () {
                input.value = '';
                input.focus();
                applyFilters();
            });
        }
        filters.forEach(function (button) {
            button.addEventListener('click', function () {
                activeFilter = button.getAttribute('data-player-filter') || 'all';
                filters.forEach(function (item) { item.classList.toggle('is-active', item === button); });
                applyFilters();
            });
        });

        try {
            var playerFromUrl = new URL(window.location.href).searchParams.get('player');
            if (playerFromUrl) {
                input.value = playerFromUrl;
                applyFilters();
                window.setTimeout(function () {
                    var section = document.getElementById('participants');
                    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, root.classList.contains('intro-pending') ? 2500 : 150);
            }
        } catch (error) {}
    }

    function initDialogs() {
        document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = document.getElementById(button.getAttribute('data-open-dialog'));
                if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            });
        });
        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = button.closest('dialog');
                if (dialog) dialog.close();
            });
        });
        document.querySelectorAll('[data-player-dialog]').forEach(function (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) dialog.close();
            });
        });
    }

    function notifyButton(button, message) {
        var original = button.textContent;
        button.textContent = message;
        window.setTimeout(function () { button.textContent = original; }, 1800);
    }

    function initShareLinks() {
        document.querySelectorAll('[data-share-player]').forEach(function (button) {
            button.addEventListener('click', async function () {
                var name = button.getAttribute('data-share-player') || '';
                var url = new URL(window.location.href);
                url.search = '';
                url.hash = 'participants';
                url.searchParams.set('player', name);
                var data = { title: 'Banco de Recursos ME58', text: 'Consulta el registro de ' + name + ' en el banco ME58.', url: url.toString() };
                try {
                    if (navigator.share) {
                        await navigator.share(data);
                    } else {
                        await navigator.clipboard.writeText(data.url);
                        notifyButton(button, 'Enlace copiado');
                    }
                } catch (error) {}
            });
        });
    }

    function loadImage(source) {
        return new Promise(function (resolve, reject) {
            var image = new Image();
            image.onload = function () { resolve(image); };
            image.onerror = reject;
            image.src = source;
        });
    }

    function fitText(context, text, maxWidth, initialSize, minimumSize) {
        var size = initialSize;
        do {
            context.font = '700 ' + size + 'px Georgia, serif';
            if (context.measureText(text).width <= maxWidth) break;
            size -= 2;
        } while (size > minimumSize);
        return size;
    }

    function safeFilename(value) {
        var cleaned = String(value || '').replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, '-').slice(0, 60);
        return cleaned || 'jugador';
    }

    function initPlayerCardDownloads() {
        document.querySelectorAll('[data-download-player-card]').forEach(function (button) {
            button.addEventListener('click', async function () {
                button.disabled = true;
                var canvas = document.createElement('canvas');
                canvas.width = 1080;
                canvas.height = 1080;
                var context = canvas.getContext('2d');
                if (!context) { button.disabled = false; return; }

                var gradient = context.createRadialGradient(540, 260, 80, 540, 540, 760);
                gradient.addColorStop(0, '#12375b');
                gradient.addColorStop(0.5, '#071522');
                gradient.addColorStop(1, '#02060b');
                context.fillStyle = gradient;
                context.fillRect(0, 0, 1080, 1080);
                context.strokeStyle = '#dcae4b';
                context.lineWidth = 10;
                context.strokeRect(34, 34, 1012, 1012);
                context.strokeStyle = 'rgba(220,174,75,.42)';
                context.lineWidth = 2;
                context.strokeRect(52, 52, 976, 976);

                context.textAlign = 'center';
                context.fillStyle = '#e5b955';
                context.font = '700 28px Arial, sans-serif';
                context.fillText('ALIANZA ME58 · CÁMARA DEL TESORO', 540, 120);
                var player = button.getAttribute('data-player') || '';
                fitText(context, player, 900, 74, 34);
                context.fillStyle = '#ffffff';
                context.fillText(player, 540, 222);
                context.fillStyle = button.getAttribute('data-status') === 'Clasificado' ? '#e9c96d' : '#e98f4f';
                context.font = '800 31px Arial, sans-serif';
                context.fillText((button.getAttribute('data-status') || '').toUpperCase(), 540, 278);

                var resourceData = [
                    ['Comida', 'food', button.getAttribute('data-food')],
                    ['Madera', 'wood', button.getAttribute('data-wood')],
                    ['Piedra', 'stone', button.getAttribute('data-stone')],
                    ['Oro', 'gold', button.getAttribute('data-gold')]
                ];
                try {
                    var images = await Promise.all(resourceData.map(function (item) { return loadImage('assets/images/resource-' + item[1] + '.webp'); }));
                    resourceData.forEach(function (item, index) {
                        var x = index % 2 === 0 ? 92 : 554;
                        var y = index < 2 ? 350 : 650;
                        context.fillStyle = 'rgba(7,18,31,.92)';
                        context.strokeStyle = 'rgba(220,174,75,.45)';
                        context.lineWidth = 2;
                        context.beginPath();
                        roundedRectangle(context, x, y, 434, 244, 28);
                        context.closePath();
                        context.fill();
                        context.stroke();
                        context.drawImage(images[index], x + 20, y + 28, 178, 178);
                        context.textAlign = 'left';
                        context.fillStyle = '#aebdca';
                        context.font = '700 24px Arial, sans-serif';
                        context.fillText(item[0].toUpperCase(), x + 212, y + 92);
                        context.fillStyle = '#ffffff';
                        context.font = '800 39px Arial, sans-serif';
                        context.fillText(item[2] || '0', x + 212, y + 146);
                        context.textAlign = 'center';
                    });
                } catch (error) {}

                context.fillStyle = '#8fa2b5';
                context.font = '500 22px Arial, sans-serif';
                context.fillText('Registro informativo · recursos según la regla vigente del evento', 540, 1000);
                canvas.toBlob(function (blob) {
                    if (!blob) { button.disabled = false; return; }
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'ME58-' + safeFilename(player) + '.png';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
                    button.disabled = false;
                }, 'image/png');
            });
        });
    }

    function compactNumber(value) {
        var units = [[1000000000, 'B'], [1000000, 'M'], [1000, 'K']];
        for (var i = 0; i < units.length; i++) {
            if (Math.abs(value) >= units[i][0]) {
                var number = value / units[i][0];
                var digits = Math.abs(number) >= 100 ? 0 : (Math.abs(number) >= 10 ? 1 : 2);
                return number.toLocaleString('es-ES', { maximumFractionDigits: digits }) + units[i][1];
            }
        }
        return Math.round(value).toLocaleString('es-ES');
    }

    function initCounters() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        document.querySelectorAll('[data-counter]').forEach(function (element) {
            var target = Number(element.getAttribute('data-target'));
            var finalText = element.getAttribute('data-final') || compactNumber(target);
            if (!Number.isFinite(target) || target <= 0) return;
            var started = performance.now();
            function frame(now) {
                var progress = Math.min(1, (now - started) / 900);
                var eased = 1 - Math.pow(1 - progress, 3);
                element.textContent = compactNumber(target * eased);
                if (progress < 1) requestAnimationFrame(frame);
                else element.textContent = finalText;
            }
            requestAnimationFrame(frame);
        });
    }

    function initCountdowns() {
        document.querySelectorAll('[data-countdown]').forEach(function (element) {
            var target = new Date(element.getAttribute('data-countdown')).getTime();
            if (!Number.isFinite(target)) return;
            function update() {
                var difference = target - Date.now();
                if (difference <= 0) { element.textContent = 'Plazo finalizado'; return; }
                var days = Math.floor(difference / 86400000);
                var hours = Math.floor((difference % 86400000) / 3600000);
                var minutes = Math.floor((difference % 3600000) / 60000);
                element.textContent = days + 'd ' + hours + 'h ' + minutes + 'm';
            }
            update();
            window.setInterval(update, 60000);
        });
    }

    function registerContributionTool() {
        var context = document.modelContext;
        var form = document.querySelector('[data-webmcp-contribution-form]');
        if (!context || typeof context.registerTool !== 'function' || !form) return;
        var lifecycle = new AbortController();
        var resources = ['food', 'wood', 'stone', 'gold'];
        var labels = { food: 'comida', wood: 'madera', stone: 'piedra', gold: 'oro' };

        function normalizeAmount(value, label) {
            var text = String(value === undefined || value === null ? '0' : value).trim();
            if (!/^\d+(?:[.,]\d{1,3})?[KkMmBb]$/.test(text) && !/^\d+$/.test(text) && !/^\d{1,3}(?:[.,]\d{3})+$/.test(text)) {
                throw new Error('La cantidad de ' + label + ' no tiene un formato válido.');
            }
            return text;
        }

        var registration = context.registerTool({
            name: 'prepare_resource_contribution',
            title: 'Preparar envío recibido',
            description: 'Rellena el formulario privado con el nombre exacto y las cantidades que realmente llegaron al banco. El impuesto de salida no modifica estos datos. Requiere revisión y confirmación visible antes de guardar.',
            inputSchema: {
                type: 'object',
                properties: {
                    playerName: { type: 'string', minLength: 1, maxLength: 80 },
                    food: { type: ['string', 'number'] },
                    wood: { type: ['string', 'number'] },
                    stone: { type: ['string', 'number'] },
                    gold: { type: ['string', 'number'] },
                    note: { type: 'string', maxLength: 180 }
                },
                required: ['playerName', 'food', 'wood', 'stone', 'gold'],
                additionalProperties: false
            },
            annotations: { readOnlyHint: false, untrustedContentHint: true },
            execute: async function (input) {
                var name = String(input.playerName || '').trim();
                if (!name || name.length > 80) throw new Error('El nombre no es válido.');
                var select = form.querySelector('[name="player_id"]');
                var nameInput = form.querySelector('[name="player_name"]');
                if (!nameInput) throw new Error('No se encontró el formulario.');
                var matched = null;
                if (select) {
                    Array.prototype.slice.call(select.options).some(function (option) {
                        if (option.value && normalizeText(option.textContent) === normalizeText(name)) { matched = option; return true; }
                        return false;
                    });
                }
                if (matched) { select.value = matched.value; nameInput.value = ''; }
                else { if (select) select.value = ''; nameInput.value = name; }
                resources.forEach(function (resource) {
                    var field = form.querySelector('[name="' + resource + '"]');
                    if (!field) throw new Error('Falta el campo ' + resource + '.');
                    field.value = normalizeAmount(input[resource], labels[resource]);
                });
                var note = form.querySelector('[name="note"]');
                if (note) note.value = String(input.note || '');
                form.classList.add('is-tool-prepared');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var submit = form.querySelector('[data-webmcp-submit]');
                if (submit) submit.focus({ preventScroll: true });
                return { status: 'prepared', playerName: name, nextAction: 'Revisar y pulsar Registrar envío.' };
            }
        }, { signal: lifecycle.signal });
        Promise.resolve(registration).catch(function () {});
        window.addEventListener('pagehide', function () { lifecycle.abort(); }, { once: true });
    }


    /* =====================================================================
     * Editor de comunicados por bloques
     * =====================================================================
     * Cada bloque es un <fieldset> con controles HTML normales. El servidor
     * genera las plantillas, así que el marcado de un bloque nuevo es idéntico
     * al de uno ya guardado. Los nombres de campo se renumeran siempre antes
     * de enviar, de modo que el orden visual es el orden guardado.
     * ===================================================================== */

    function announcementEditor() {
        var form = document.querySelector('[data-announcement-editor]');
        if (!form) return null;
        var list = form.querySelector('[data-an-blocks]');
        var empty = form.querySelector('[data-an-empty]');
        if (!list) return null;

        function reindex() {
            var blocks = list.querySelectorAll('[data-an-block]');
            Array.prototype.forEach.call(blocks, function (block, index) {
                block.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace(/^blocks\[[^\]]*\]/, 'blocks[' + index + ']');
                });
                block.querySelectorAll('[data-testid^="block-"]').forEach(function (field) {
                    field.setAttribute('data-testid', field.getAttribute('data-testid').replace(/^block-[^-]*-/, 'block-' + index + '-'));
                });
            });
            if (empty) empty.hidden = blocks.length > 0;
            return blocks.length;
        }

        function addBlock(type, values) {
            var template = form.querySelector('[data-an-template="' + type + '"]');
            if (!template) return null;
            var fragment = template.content.cloneNode(true);
            var block = fragment.querySelector('[data-an-block]');
            list.appendChild(fragment);
            reindex();
            if (block && values) {
                Object.keys(values).forEach(function (key) {
                    var field = block.querySelector('[name$="[' + key + ']"], [name$="[' + key + '][]"]');
                    if (!field) return;
                    if (field.type === 'checkbox') { field.checked = Boolean(values[key]); return; }
                    if (field.multiple && Array.isArray(values[key])) {
                        Array.prototype.forEach.call(field.options, function (option) {
                            option.selected = values[key].indexOf(Number(option.value)) !== -1;
                        });
                        return;
                    }
                    field.value = String(values[key]);
                });
            }
            return block;
        }

        function wrapSelection(field, before, after) {
            if (!field) return;
            var start = field.selectionStart === null ? field.value.length : field.selectionStart;
            var end = field.selectionEnd === null ? field.value.length : field.selectionEnd;
            var selected = field.value.slice(start, end) || 'texto';
            field.value = field.value.slice(0, start) + before + selected + after + field.value.slice(end);
            field.focus();
            field.selectionStart = start + before.length;
            field.selectionEnd = start + before.length + selected.length;
        }

        function textFieldOf(block) {
            return block ? block.querySelector('[data-an-text]') : null;
        }

        form.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-an-add]');
            if (addButton) {
                event.preventDefault();
                var created = addBlock(addButton.getAttribute('data-an-add'));
                if (created) {
                    created.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    var firstField = created.querySelector('input:not([type="hidden"]), textarea, select');
                    if (firstField) firstField.focus({ preventScroll: true });
                }
                return;
            }
            var block = event.target.closest('[data-an-block]');
            if (!block) return;
            if (event.target.closest('[data-an-remove]')) {
                event.preventDefault();
                block.remove();
                reindex();
                return;
            }
            var move = event.target.closest('[data-an-move]');
            if (move) {
                event.preventDefault();
                if (move.getAttribute('data-an-move') === 'up' && block.previousElementSibling) {
                    list.insertBefore(block, block.previousElementSibling);
                } else if (move.getAttribute('data-an-move') === 'down' && block.nextElementSibling) {
                    list.insertBefore(block.nextElementSibling, block);
                }
                reindex();
                return;
            }
            var wrap = event.target.closest('[data-an-wrap]');
            if (wrap) {
                event.preventDefault();
                var marker = wrap.getAttribute('data-an-wrap');
                wrapSelection(textFieldOf(block), marker, marker);
                return;
            }
            if (event.target.closest('[data-an-link]')) {
                event.preventDefault();
                var url = window.prompt('Dirección del enlace (https://, http:// o mailto:)', 'https://');
                if (!url) return;
                var field = textFieldOf(block);
                if (!field) return;
                var start = field.selectionStart || 0;
                var end = field.selectionEnd || 0;
                var label = field.value.slice(start, end) || 'texto del enlace';
                field.value = field.value.slice(0, start) + '[' + label + '](' + url + ')' + field.value.slice(end);
                field.focus();
            }
        });

        form.addEventListener('change', function (event) {
            var block = event.target.closest('[data-an-block]');
            if (!block) return;
            if (event.target.matches('[data-an-color]') && event.target.value) {
                wrapSelection(textFieldOf(block), '{c:' + event.target.value + '}', '{/c}');
                event.target.value = '';
            }
            if (event.target.matches('[data-an-highlight]') && event.target.value) {
                wrapSelection(textFieldOf(block), '{f:' + event.target.value + '}', '{/f}');
                event.target.value = '';
            }
        });

        form.addEventListener('submit', reindex);
        reindex();

        return { form: form, addBlock: addBlock, reindex: reindex };
    }

    function registerAnnouncementTool(editor) {
        var context = document.modelContext;
        if (!context || typeof context.registerTool !== 'function' || !editor) return;
        var lifecycle = new AbortController();

        var registration = context.registerTool({
            name: 'prepare_announcement_draft',
            title: 'Preparar borrador de comunicado',
            description: 'Rellena el editor privado de comunicados con un título, un resumen y una lista de bloques, y deja abierta la vista previa. Nunca publica: la publicación exige una revisión y una acción explícita del administrador.',
            inputSchema: {
                type: 'object',
                properties: {
                    title: { type: 'string', minLength: 3, maxLength: 200 },
                    summary: { type: 'string', maxLength: 400 },
                    category: { type: 'string', enum: ['anuncio', 'reglas', 'resultados', 'banco', 'recordatorio', 'otra'] },
                    blocks: {
                        type: 'array',
                        maxItems: 200,
                        items: {
                            type: 'object',
                            properties: {
                                type: { type: 'string', enum: ['heading', 'paragraph', 'list', 'quote', 'callout', 'divider', 'button', 'image', 'gallery', 'video', 'audio'] },
                                source: { type: 'string' },
                                title: { type: 'string' },
                                href: { type: 'string' },
                                caption: { type: 'string' },
                                alt: { type: 'string' },
                                tone: { type: 'string' },
                                level: { type: 'number' },
                                ordered: { type: 'boolean' },
                                media_id: { type: 'number' },
                                poster_media_id: { type: 'number' },
                                gallery_ids: { type: 'array', items: { type: 'number' } }
                            },
                            required: ['type'],
                            additionalProperties: false
                        }
                    }
                },
                required: ['title', 'blocks'],
                additionalProperties: false
            },
            annotations: { readOnlyHint: false, untrustedContentHint: true },
            execute: async function (input) {
                var form = editor.form;
                var title = String(input.title || '').trim();
                if (title.length < 3) throw new Error('El título debe tener al menos 3 caracteres.');
                var titleField = form.querySelector('[name="title"]');
                if (!titleField) throw new Error('No se encontró el editor de comunicados.');
                titleField.value = title;
                var summaryField = form.querySelector('[name="summary"]');
                if (summaryField) summaryField.value = String(input.summary || '');
                if (input.category) {
                    var categoryField = form.querySelector('[name="category"]');
                    if (categoryField) categoryField.value = String(input.category);
                }
                form.querySelectorAll('[data-an-block]').forEach(function (block) { block.remove(); });
                var added = 0;
                (input.blocks || []).forEach(function (block) {
                    var values = {};
                    ['source', 'title', 'href', 'caption', 'alt', 'tone', 'level', 'ordered', 'media_id', 'poster_media_id'].forEach(function (key) {
                        if (block[key] !== undefined && block[key] !== null) values[key] = block[key];
                    });
                    if (Array.isArray(block.gallery_ids)) values.gallery_ids = block.gallery_ids.map(Number);
                    if (editor.addBlock(String(block.type), values)) added++;
                });
                editor.reindex();
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var preview = form.querySelector('[data-testid="announcement-preview-button"]');
                if (preview) preview.focus({ preventScroll: true });
                return {
                    status: 'prepared',
                    blocks: added,
                    nextAction: 'Pulsa «Vista previa» para ver el resultado exacto y después «Guardar borrador». Publicar sigue siendo una acción manual.'
                };
            }
        }, { signal: lifecycle.signal });
        Promise.resolve(registration).catch(function () {});
        window.addEventListener('pagehide', function () { lifecycle.abort(); }, { once: true });
    }

    function registerEventConfigurationTool() {
        var context = document.modelContext;
        var form = document.querySelector('[data-testid="event-settings-form"]') || document.querySelector('[data-testid="prize-settings-form"]');
        if (!context || typeof context.registerTool !== 'function' || !form) return;
        var lifecycle = new AbortController();
        var fields = ['event_name', 'objective_title', 'objective_description', 'score_metric_label', 'ranking_method',
            'rank_rule_first', 'rank_rule_second', 'rank_rule_third', 'prize_pool_percent',
            'threshold_food', 'threshold_wood', 'threshold_stone', 'threshold_gold',
            'reward_weight_first', 'reward_weight_second', 'reward_weight_third',
            'reserve_draw_food', 'reserve_draw_wood', 'reserve_draw_stone', 'reserve_draw_gold'];

        var registration = context.registerTool({
            name: 'prepare_event_configuration',
            title: 'Preparar configuración del evento',
            description: 'Rellena el formulario de configuración del evento con el objetivo, la métrica, las cuotas, el porcentaje del fondo, los pesos y la reserva que se quiere usar. No guarda nada: el administrador revisa la vista previa y confirma manualmente.',
            inputSchema: {
                type: 'object',
                properties: fields.reduce(function (schema, key) {
                    schema[key] = { type: ['string', 'number'] };
                    return schema;
                }, {}),
                additionalProperties: false
            },
            annotations: { readOnlyHint: false, untrustedContentHint: true },
            execute: async function (input) {
                var applied = [];
                fields.forEach(function (key) {
                    if (input[key] === undefined || input[key] === null) return;
                    var field = form.querySelector('[name="' + key + '"]');
                    if (!field) return;
                    field.value = String(input[key]);
                    applied.push(key);
                });
                if (applied.length === 0) throw new Error('Ningún campo indicado existe en este formulario. Abre la pestaña Evento o Premio y reserva.');
                form.classList.add('is-tool-prepared');
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var submit = form.querySelector('button[type="submit"]');
                if (submit) submit.focus({ preventScroll: true });
                return {
                    status: 'prepared',
                    fields: applied,
                    nextAction: 'Revisa la vista previa del reparto y pulsa Guardar. Congelar la liquidación sigue siendo una acción aparte.'
                };
            }
        }, { signal: lifecycle.signal });
        Promise.resolve(registration).catch(function () {});
        window.addEventListener('pagehide', function () { lifecycle.abort(); }, { once: true });
    }

    initIntro();
    initPasswordButtons();
    initConfirmations();
    initFlashMessages();
    initPlayerPickers();
    initBatchCounter();
    initPlayerSearch();
    initDialogs();
    initShareLinks();
    initPlayerCardDownloads();
    initCounters();
    initCountdowns();
    try { registerContributionTool(); } catch (error) {}
    var announcementEditorInstance = null;
    try { announcementEditorInstance = announcementEditor(); } catch (error) {}
    try { registerAnnouncementTool(announcementEditorInstance); } catch (error) {}
    try { registerEventConfigurationTool(); } catch (error) {}
})();
