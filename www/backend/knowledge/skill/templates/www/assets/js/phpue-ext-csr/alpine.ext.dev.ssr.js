// alpine.ext.ssr.js
// Author: Edward Patch
// Description: Alpine.js extension for Server-Side Rendering (SSR) support (x-for)
// Website: https://phpue.co.uk/ue-extensions

// DEV MODE FLAG - Change to true to enable debug logging
const DEVMODE = true;

(function() {
    'use strict';
    
    if(DEVMODE) console.log('🎯 Alpine.js x-ssr extension loading...');

    const escapeHTML = str =>
        String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    
    // Wait for alpine:init event
    document.addEventListener('alpine:init', function() {
        if(DEVMODE) console.log('✅ alpine:init fired, registering x-ssr directive...');
        
        // Register x-ssr directive
        Alpine.directive('ssr', (el, { expression }, { Alpine, effect, evaluate }) => {
            if(DEVMODE)
                console.log(`🔄 x-ssr initializing for: ${expression}`, el);
            
            // Get the component
            const component = Alpine.closestDataStack(el);
            if (!component) {
                if(DEVMODE) console.error('x-ssr: No Alpine component found');
                return;
            }
            
            // Handle array from closestDataStack
            let actualComponent = component;
            if (Array.isArray(component) && component.length > 0) {
                actualComponent = component[component.length - 1];
            }
            
            const itemKey = el.getAttribute('x-ssr-key') || 'id';
            const templateName = el.getAttribute('x-ssr-template') || 'csrTemplate';
            
            if(DEVMODE) console.log(`🔧 x-ssr config: key="${itemKey}", template="${templateName}"`);
            
            // Store server-rendered items (direct children or wrapped in divs)
            const serverItems = [];
            const directItems = el.querySelectorAll(`:scope > [data-${itemKey}]`);
            directItems.forEach(item => serverItems.push(item));
            const childDivs = el.querySelectorAll(':scope > div');
            childDivs.forEach(childDiv => {
                const items = childDiv.querySelectorAll(`[data-${itemKey}]`);
                items.forEach(item => serverItems.push(item));
            });
            
            const serverItemsMap = new Map();
            serverItems.forEach(item => {
                const key = item.getAttribute(`data-${itemKey}`);
                if (key) serverItemsMap.set(key, { element: item, key });
            });
            
            if(DEVMODE) console.log(`📦 x-ssr: Found ${serverItemsMap.size} server-rendered items`);
            
            const domItems = new Map(serverItemsMap);
            
            function getItemKey(dataItem) {
                return typeof dataItem === 'object' ? String(dataItem[itemKey]) : String(dataItem);
            }
            
            function getCSRTemplate(comp, templateName) {
                if(DEVMODE) console.log(`🔍 Looking for template '${templateName}' in component`);
                
                let template = null;
                
                // Try direct access
                if (comp[templateName] !== undefined) {
                    template = comp[templateName];
                    if(DEVMODE) console.log(`✅ Found template via direct access`);
                }
                
                // Try $data
                if (!template && comp.$data && comp.$data[templateName]) {
                    template = comp.$data[templateName];
                    if(DEVMODE) console.log(`✅ Found template via $data`);
                }
                
                // Try evaluate
                if (!template) {
                    try {
                        template = evaluate(templateName);
                        if(DEVMODE) console.log(`✅ Found template via evaluate`);
                    } catch (e) {
                        if(DEVMODE) console.log(`⚠️ Could not evaluate '${templateName}':`, e.message);
                    }
                }
                
                if (!template) {
                    console.error(`❌ No template found: ${templateName}`);
                    return null;
                }
                
                // Normalize
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
                if(DEVMODE) console.log(`🛠️ Creating CSR item for key: ${key}`);
                
                const template = getCSRTemplate(actualComponent, templateName);
                
                if (!template) {
                    console.error('x-ssr: No CSR template available');
                    return null;
                }
                
                let html = template.html;
                
                if (typeof dataItem === 'object') {
                    Object.keys(dataItem).forEach(prop => {
                        const placeholder = `\${item.${prop}}`;
                        const value = escapeHTML(dataItem[prop] ?? '');
                        while (html.includes(placeholder)) {
                            html = html.replace(placeholder, value);
                        }
                    });
                }
                
                // Parse HTML in a context-aware container when parent is table-related.
                // Otherwise keep legacy div parsing for broad compatibility.
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
                        template.process(newElement, dataItem, actualComponent);
                    } catch (e) {
                        console.error('x-ssr: Error in template.process:', e);
                    }
                }
                
                if(DEVMODE) console.log(`✅ Created CSR element`);
                return newElement;
            }
            
            // WATCH FOR DATA CHANGES
            effect(() => {
                try {
                    const dataItems = evaluate(expression);
                    
                    if (!Array.isArray(dataItems)) {
                        console.error('x-ssr: Expression must evaluate to an array');
                        return;
                    }
                    
                    if(DEVMODE) console.log(`📊 x-ssr: Data updated with ${dataItems.length} items`, dataItems);
                    
                    const dataItemsMap = new Map();
                    const orderedKeys = [];
                    dataItems.forEach(item => {
                        const key = getItemKey(item);
                        orderedKeys.push(key);
                        dataItemsMap.set(key, item);
                    });
                    
                    // Remove items not in data
                    domItems.forEach((domInfo, key) => {
                        if (!dataItemsMap.has(key)) {
                            if(DEVMODE) console.log(`🗑️ x-ssr: Removing item ${key}`);
                            domInfo.element.remove();
                            domItems.delete(key);
                        }
                    });
                    
                    // Ensure all items exist, refresh CSR items, and enforce order.
                    orderedKeys.forEach(key => {
                        const dataItem = dataItemsMap.get(key);
                        let domInfo = domItems.get(key);

                        if (!domInfo) {
                            if(DEVMODE) console.log(`➕ x-ssr: Adding item ${key}`);
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
                            // Re-render existing CSR nodes so value/markup updates are applied.
                            const refreshedElement = createCSRItem(dataItem);
                            if (refreshedElement) {
                                domInfo.element.replaceWith(refreshedElement);
                                domInfo = { element: refreshedElement, key, isCSR: true };
                                domItems.set(key, domInfo);
                            }
                        }

                        // Appending an existing node moves it, giving stable order updates.
                        el.appendChild(domInfo.element);
                    });
                    
                } catch (error) {
                    console.error('x-ssr: Error processing data', error);
                }
            });
            
            if(DEVMODE) console.log('✅ x-ssr directive ready for', expression);
        });
        
        if(DEVMODE) console.log('✅ x-ssr directive registered!');
    });
    
    // If Alpine is already loaded, trigger the event manually
    if (window.Alpine) {
        if(DEVMODE) console.log('⚠️ Alpine already loaded, triggering alpine:init...');
        document.dispatchEvent(new CustomEvent('alpine:init'));
    }
})();
