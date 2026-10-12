// alpine.ext.prod.ssr.js
// Production x-ssr runtime (unminified)

(function() {
    'use strict';

    const escapeHTML = (str) =>
        String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    document.addEventListener('alpine:init', function() {
        Alpine.directive('ssr', (el, { expression }, { Alpine, effect, evaluate }) => {
            let component = Alpine.closestDataStack(el);
            if (!component) {
                console.error('x-ssr: No Alpine component found');
                return;
            }

            if (Array.isArray(component) && component.length > 0) {
                component = component[component.length - 1];
            }

            const itemKey = el.getAttribute('x-ssr-key') || 'id';
            const templateName = el.getAttribute('x-ssr-template') || 'csrTemplate';

            const serverItems = [];
            const directItems = el.querySelectorAll(`:scope > [data-${itemKey}]`);
            directItems.forEach((item) => serverItems.push(item));

            const childDivs = el.querySelectorAll(':scope > div');
            childDivs.forEach((childDiv) => {
                const items = childDiv.querySelectorAll(`[data-${itemKey}]`);
                items.forEach((item) => serverItems.push(item));
            });

            const serverItemsMap = new Map();
            serverItems.forEach((item) => {
                const key = item.getAttribute(`data-${itemKey}`);
                if (key) serverItemsMap.set(key, { element: item, key });
            });

            const domItems = new Map(serverItemsMap);

            function getItemKey(dataItem) {
                return typeof dataItem === 'object' ? String(dataItem[itemKey]) : String(dataItem);
            }

            function getCSRTemplate(comp, name) {
                let template = null;

                if (comp[name] !== undefined) {
                    template = comp[name];
                }

                if (!template && comp.$data && comp.$data[name]) {
                    template = comp.$data[name];
                }

                if (!template) {
                    try {
                        template = evaluate(name);
                    } catch (error) {
                        console.warn(`⚠️ Could not evaluate '${name}':`, error.message);
                    }
                }

                if (!template) {
                    console.error(`❌ No template found: ${name}`);
                    return null;
                }

                if (typeof template === 'object' && template.html) {
                    return template;
                }

                if (typeof template === 'string') {
                    return { html: template };
                }

                return null;
            }

            function createCSRItem(dataItem) {
                const key = getItemKey(dataItem);
                const template = getCSRTemplate(component, templateName);

                if (!template) {
                    console.error('x-ssr: No CSR template available');
                    return null;
                }

                let html = template.html;

                if (typeof dataItem === 'object' && dataItem !== null) {
                    Object.keys(dataItem).forEach((prop) => {
                        const placeholder = `\${item.${prop}}`;
                        const value = escapeHTML(dataItem[prop] ?? '');
                        while (html.includes(placeholder)) {
                            html = html.replace(placeholder, value);
                        }
                    });
                }

                let newElement = null;
                const parentTag = String(el.tagName || '').toUpperCase();

                if (parentTag === 'TBODY' || parentTag === 'THEAD' || parentTag === 'TFOOT') {
                    const table = document.createElement('table');
                    const section = document.createElement(parentTag.toLowerCase());
                    table.appendChild(section);
                    section.innerHTML = html.trim();
                    newElement = section.firstElementChild || section.firstChild;
                } else if (parentTag === 'TR') {
                    const table = document.createElement('table');
                    const tbody = document.createElement('tbody');
                    const tr = document.createElement('tr');
                    table.appendChild(tbody);
                    tbody.appendChild(tr);
                    tr.innerHTML = html.trim();
                    newElement = tr.firstElementChild || tr.firstChild;
                } else {
                    const temp = document.createElement('div');
                    temp.innerHTML = html.trim();
                    newElement = temp.firstChild;
                }

                if (!newElement) {
                    console.error('x-ssr: Failed to create element');
                    return null;
                }

                newElement.setAttribute(`data-${itemKey}`, key);

                if (typeof template.process === 'function') {
                    try {
                        template.process(newElement, dataItem, component);
                    } catch (error) {
                        console.error('x-ssr: Error in template.process:', error);
                    }
                }

                return newElement;
            }

            effect(() => {
                try {
                    const dataItems = evaluate(expression);

                    if (!Array.isArray(dataItems)) {
                        console.error('x-ssr: Expression must evaluate to an array');
                        return;
                    }

                    const dataItemsMap = new Map();
                    const orderedKeys = [];

                    dataItems.forEach((item) => {
                        const key = getItemKey(item);
                        orderedKeys.push(key);
                        dataItemsMap.set(key, item);
                    });

                    domItems.forEach((domInfo, key) => {
                        if (!dataItemsMap.has(key)) {
                            domInfo.element.remove();
                            domItems.delete(key);
                        }
                    });

                    orderedKeys.forEach((key) => {
                        const dataItem = dataItemsMap.get(key);
                        let domInfo = domItems.get(key);

                        if (!domInfo) {
                            const serverInfo = serverItemsMap.get(key);
                            if (serverInfo) {
                                domInfo = serverInfo;
                            } else {
                                const newElement = createCSRItem(dataItem);
                                if (!newElement) return;
                                domInfo = { element: newElement, key, isCSR: true };
                            }
                            domItems.set(key, domInfo);
                        } else if (domInfo.isCSR) {
                            const refreshedElement = createCSRItem(dataItem);
                            if (refreshedElement) {
                                domInfo.element.replaceWith(refreshedElement);
                                domInfo = { element: refreshedElement, key, isCSR: true };
                                domItems.set(key, domInfo);
                            }
                        }

                        el.appendChild(domInfo.element);
                    });
                } catch (error) {
                    console.error('x-ssr: Error processing data', error);
                }
            });
        });
    });

    if (window.Alpine) {
        document.dispatchEvent(new CustomEvent('alpine:init'));
    }
})();
