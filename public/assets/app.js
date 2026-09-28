/**
 * SYNCDERO - Minimalist Modular & Draggable Node Flow
 * Notion / AI-Flow inspired clean canvas interaction
 */

document.addEventListener('DOMContentLoaded', () => {
    initThemeToggle();
    initProfileAndSession();
    initDraggableNodes();
    initViewMode();
    updateConnectionWires();
    initLazyStatusChecker();
});


// 1. Theme Management (Light Mode Default with Alt Dark Mode)
function initThemeToggle() {
    const savedTheme = localStorage.getItem('syncdero-theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('syncdero-theme', next);
            updateThemeIcon(next);
        });
    }
}

function updateThemeIcon(theme) {
    const moon = document.getElementById('iconMoon');
    const sun = document.getElementById('iconSun');
    const logosLight = document.querySelectorAll('.logo-light');
    const logosDark = document.querySelectorAll('.logo-dark');

    if (moon && sun) {
        if (theme === 'dark') {
            moon.style.display = 'none';
            sun.style.display = 'inline-block';
            logosLight.forEach(el => el.style.display = 'none');
            logosDark.forEach(el => el.style.display = 'inline-block');
        } else {
            moon.style.display = 'inline-block';
            sun.style.display = 'none';
            logosLight.forEach(el => el.style.display = 'inline-block');
            logosDark.forEach(el => el.style.display = 'none');
        }
    }
}



// 2. Modular Draggable Nodes
let activeDragNode = null;
let dragOffsetX = 0;
let dragOffsetY = 0;
let zIndexCounter = 30;

function initDraggableNodes() {
    const nodes = document.querySelectorAll('.flow-node');
    const viewport = document.getElementById('flowViewport');

    nodes.forEach(node => {
        const header = node.querySelector('.node-header') || node;
        
        header.addEventListener('mousedown', (e) => {
            if (e.target.closest('button') || e.target.closest('a')) return;
            
            activeDragNode = node;
            node.style.zIndex = ++zIndexCounter;
            
            const rect = node.getBoundingClientRect();
            dragOffsetX = e.clientX - rect.left;
            dragOffsetY = e.clientY - rect.top;
            
            document.body.style.userSelect = 'none';
        });
    });

    window.addEventListener('mousemove', (e) => {
        if (!activeDragNode || !viewport) return;
        
        const viewportRect = viewport.getBoundingClientRect();
        let newX = e.clientX - viewportRect.left - dragOffsetX + viewport.parentElement.scrollLeft;
        let newY = e.clientY - viewportRect.top - dragOffsetY + viewport.parentElement.scrollTop;

        // Limites
        newX = Math.max(16, newX);
        newY = Math.max(16, newY);

        activeDragNode.style.left = `${newX}px`;
        activeDragNode.style.top = `${newY}px`;

        updateConnectionWires();
    });

    window.addEventListener('mouseup', () => {
        if (activeDragNode) {
            activeDragNode = null;
            document.body.style.userSelect = '';
        }
    });

    window.addEventListener('resize', updateConnectionWires);
}

// 3. Render Curved Bezier Connection Wires between Nodes
function updateConnectionWires() {
    const svg = document.getElementById('flowSvgWires');
    const viewport = document.getElementById('flowViewport');
    if (!svg || !viewport) return;

    const topologySubView = document.getElementById('canvasTopologySubView');
    const isTopology = topologySubView && topologySubView.style.display !== 'none';

    let pathsHtml = '';
    const viewportRect = viewport.getBoundingClientRect();

    if (isTopology) {
        // Cableado Topológico: Git -> Prod, Prod -> Staging, Prod -> Espejos
        const gitNode = document.getElementById('nodeGitSource');
        const prodNode = document.getElementById('nodeProdDb');
        const stagingNode = document.getElementById('nodeStagingDb');
        const mirrorNodes = document.querySelectorAll('[id^="nodeDevMirror_"]');

        if (gitNode && prodNode) {
            pathsHtml += drawBezierWire(gitNode, prodNode, viewportRect, 'flow-wire solid');
        }
        if (prodNode && stagingNode) {
            pathsHtml += drawBezierWire(prodNode, stagingNode, viewportRect, 'flow-wire');
        }
        if (prodNode) {
            mirrorNodes.forEach(mNode => {
                pathsHtml += drawBezierWire(prodNode, mNode, viewportRect, 'flow-wire');
            });
        }
    } else {
        // Conectar nodos de lotes en secuencia
        const nodes = Array.from(document.querySelectorAll('.batch-flow-node'));
        for (let i = 0; i < nodes.length - 1; i++) {
            pathsHtml += drawBezierWire(nodes[i], nodes[i + 1], viewportRect, nodes[i + 1].dataset.status === 'applied' ? 'flow-wire solid' : 'flow-wire');
        }
    }

    svg.innerHTML = pathsHtml;
}

function drawBezierWire(fromNode, toNode, viewportRect, strokeClass = 'flow-wire') {
    const fromRect = fromNode.getBoundingClientRect();
    const toRect = toNode.getBoundingClientRect();

    const x1 = fromRect.right - viewportRect.left;
    const y1 = fromRect.top + (fromRect.height / 2) - viewportRect.top;

    const x2 = toRect.left - viewportRect.left;
    const y2 = toRect.top + (toRect.height / 2) - viewportRect.top;

    const dx = Math.abs(x2 - x1) * 0.5;
    const cx1 = x1 + dx;
    const cy1 = y1;
    const cx2 = x2 - dx;
    const cy2 = y2;

    return `<path d="M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${x2} ${y2}" class="${strokeClass}" />`;
}

function switchCanvasSubView(subview) {
    const topoView = document.getElementById('canvasTopologySubView');
    const batchView = document.getElementById('canvasBatchesSubView');
    const topoBtn = document.getElementById('canvasSubViewTopologyBtn');
    const batchBtn = document.getElementById('canvasSubViewBatchesBtn');

    if (subview === 'batches') {
        if (topoView) topoView.style.display = 'none';
        if (batchView) batchView.style.display = 'block';
        if (topoBtn) topoBtn.classList.remove('active');
        if (batchBtn) batchBtn.classList.add('active');
    } else {
        if (topoView) topoView.style.display = 'block';
        if (batchView) batchView.style.display = 'none';
        if (topoBtn) topoBtn.classList.add('active');
        if (batchBtn) batchBtn.classList.remove('active');
    }
    setTimeout(updateConnectionWires, 40);
}

function toggleProjectDropdown(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('projectDropdownMenu');
    if (menu) {
        menu.classList.toggle('open');
    }
}

function closeProjectDropdown() {
    const menu = document.getElementById('projectDropdownMenu');
    if (menu) {
        menu.classList.remove('open');
    }
}

document.addEventListener('click', (e) => {
    const container = document.querySelector('.project-selector-container');
    if (container && !container.contains(e.target)) {
        closeProjectDropdown();
    }
});

// Modales con animación fluida de transitions.dev
function smoothCloseModal(modalId) {
    const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
    if (!modal || (!modal.classList.contains('open') && !modal.classList.contains('is-closing'))) return;
    modal.classList.add('is-closing');
    modal.classList.remove('open');
    setTimeout(() => {
        modal.classList.remove('is-closing');
    }, 160);
}

function openSyncModal() {
    const modal = document.getElementById('syncModal');
    if (modal) modal.classList.add('open');
}

function closeSyncModal() {
    smoothCloseModal('syncModal');
}

function openServerModal() {
    const modal = document.getElementById('serverModal');
    if (modal) modal.classList.add('open');
}

function closeServerModal() {
    smoothCloseModal('serverModal');
}

function openRollbackModal() {
    const modal = document.getElementById('rollbackModal');
    if (modal) modal.classList.add('open');
}

function closeRollbackModal() {
    smoothCloseModal('rollbackModal');
}

function openHelpModal() {
    const modal = document.getElementById('helpModal');
    if (!modal) return;
    modal.classList.add('open');
    const container = document.getElementById('helpModalContent');
    if (container && (!container.dataset.loaded || container.dataset.loaded === 'false')) {
        container.innerHTML = '<div style="color:var(--text-muted); padding:10px;">Cargando manual técnico...</div>';
        fetch('/?api_manual=1')
            .then(res => res.text())
            .then(markdown => {
                container.dataset.loaded = 'true';
                // Renderizado sencillo y limpio de Markdown a HTML sin librerías externas
                container.innerHTML = renderSimpleMarkdown(markdown);
            })
            .catch(() => {
                container.innerHTML = '<div style="color:var(--state-danger-text); padding:10px;">Error al cargar el manual técnico.</div>';
            });
    }
}

function closeHelpModal() {
    smoothCloseModal('helpModal');
}

function openDiffModal(filename) {
    const modal = document.getElementById('diffModal');
    const title = document.getElementById('diffModalTitle');
    const metaBox = document.getElementById('diffMetadataBox');
    const codeArea = document.getElementById('diffCodeArea');
    if (!modal) return;

    if (title) title.textContent = `Inspección: ${filename}`;
    if (metaBox) metaBox.innerHTML = '<div style="color:var(--text-muted);">Consultando estado y hashes...</div>';
    if (codeArea) codeArea.innerHTML = '<div style="color:var(--text-muted); font-size:12px;">Cargando script...</div>';

    modal.classList.add('open');

    // Obtener parámetro de entorno activo actual de la URL
    const urlParams = new URLSearchParams(window.location.search);
    const envParam = urlParams.get('env') ? `&env=${encodeURIComponent(urlParams.get('env'))}` : '';

    fetch(`/?api_preview=${encodeURIComponent(filename)}${envParam}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                if (metaBox) metaBox.innerHTML = `<span style="color:var(--state-danger-text);">${data.error || 'Error'}</span>`;
                if (codeArea) codeArea.innerHTML = '';
                return;
            }

            let statusBadge = '<span class="status-badge badge-warn">PENDIENTE (UNSTAGED)</span>';
            if (data.has_drift) {
                statusBadge = '<span class="status-badge badge-danger">DRIFT (DIVERGENCIA DE HASH)</span>';
            } else if (data.batch !== null) {
                statusBadge = `<span class="status-badge badge-sync">APLICADO (BATCH #${data.batch})</span>`;
            }

            let metaHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong>${escapeHtml(data.filename)}</strong>
                    ${statusBadge}
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; color:var(--text-secondary); font-family:var(--font-mono); font-size:10px;">
                    <div>SHA en disco: <span style="color:var(--text-primary);">${data.disk_hash ? data.disk_hash.substring(0, 16) + '...' : 'N/A'}</span></div>
                    <div>SHA en BD: <span style="color:${data.has_drift ? 'var(--state-danger-text)' : 'var(--text-primary)'};">${data.db_hash ? data.db_hash.substring(0, 16) + '...' : 'No aplicada'}</span></div>
                </div>
            `;

            if (data.applied_at || data.git_commit || data.notes) {
                metaHtml += `
                    <div style="margin-top:6px; padding-top:6px; border-top:1px dashed var(--border-color); font-size:10px; color:var(--text-muted); display:flex; gap:12px; flex-wrap:wrap;">
                        ${data.applied_at ? `<span>Aplicado: ${escapeHtml(data.applied_at)}</span>` : ''}
                        ${data.git_commit ? `<span>Commit: <code>${escapeHtml(data.git_commit)}</code></span>` : ''}
                        ${data.git_branch ? `<span>Rama: <code>${escapeHtml(data.git_branch)}</code></span>` : ''}
                        ${data.notes ? `<span>Nota: <em style="color:var(--text-primary);">${escapeHtml(data.notes)}</em></span>` : ''}
                    </div>
                `;
            }

            if (data.has_drift) {
                metaHtml += `
                    <div style="margin-top:8px; padding:6px 8px; background:var(--state-danger-bg); color:var(--state-danger-text); border:1px solid var(--state-danger-border); border-radius:3px; font-size:11px;">
                        <strong>ALERTA DE DIVERGENCIA:</strong> El contenido de este archivo en disco fue modificado después de haberse aplicado a la base de datos.
                    </div>
                `;
            }

            if (metaBox) metaBox.innerHTML = metaHtml;

            // Visor de contenido: single (disco) o comparación lado a lado (BD vs disco)
            const codeArea = document.getElementById('diffCodeArea');
            if (codeArea) {
                const preStyle = 'background: var(--bg-code, #1e1e1e); color: #d4d4d4; padding: 12px; border-radius: 4px; font-family: var(--font-mono); font-size: 11px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap; margin: 0; flex: 1; min-width: 0; align-self: stretch;';
                const labelStyle = 'font-size: 10px; font-weight: 700; margin-bottom: 4px; color: var(--text-secondary);';

                if (data.db_content && (data.has_drift || data.db_content !== data.content)) {
                    codeArea.innerHTML = `
                        <div style="display: flex; gap: 10px; max-height: 420px; overflow-x: auto; width: 100%;">
                            <div style="flex: 1; min-width: 0; display: flex; flex-direction: column;">
                                <div style="${labelStyle} color: var(--state-danger-text);">CONTENIDO APLICADO EN BD (snapshot al momento del sync):</div>
                                <pre style="${preStyle}">${escapeHtml(data.db_content)}</pre>
                            </div>
                            <div style="flex: 1; min-width: 0; display: flex; flex-direction: column;">
                                <div style="${labelStyle} color: var(--state-warn-text, #b45309);">CONTENIDO ACTUAL EN DISCO:</div>
                                <pre style="${preStyle}">${escapeHtml(data.content || '')}</pre>
                            </div>
                        </div>
                    `;
                } else {
                    codeArea.innerHTML = `<pre style="${preStyle} max-height: 420px;">${escapeHtml(data.content || '')}</pre>`;
                }
            }
        })
        .catch(err => {
            if (metaBox) metaBox.innerHTML = '<span style="color:var(--state-danger-text);">Error al consultar archivo.</span>';
            if (codeArea) codeArea.innerHTML = '';
        });
}

function closeDiffModal() {
    smoothCloseModal('diffModal');
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderSimpleMarkdown(md) {
    if (!md) return '';
    const lines = md.split('\n');
    let html = '';
    let inPre = false;
    let preContent = '';

    for (let line of lines) {
        if (line.startsWith('```')) {
            if (inPre) {
                html += `<pre style="background:var(--bg-code, #18181b); color:#e4e4e7; padding:10px; border-radius:4px; font-family:var(--font-mono); font-size:11px; overflow-x:auto; margin:8px 0;">${escapeHtml(preContent)}</pre>`;
                preContent = '';
                inPre = false;
            } else {
                inPre = true;
                preContent = '';
            }
            continue;
        }

        if (inPre) {
            preContent += (preContent ? '\n' : '') + line;
            continue;
        }

        if (line.startsWith('# ')) {
            html += `<h2 style="font-size:16px; margin:14px 0 8px 0; border-bottom:1px solid var(--border-color); padding-bottom:4px; font-weight:700;">${escapeHtml(line.slice(2))}</h2>`;
        } else if (line.startsWith('## ')) {
            html += `<h3 style="font-size:14px; margin:12px 0 6px 0; font-weight:600; color:var(--brand-primary);">${escapeHtml(line.slice(3))}</h3>`;
        } else if (line.startsWith('### ')) {
            html += `<h4 style="font-size:13px; margin:10px 0 4px 0; font-weight:600;">${escapeHtml(line.slice(4))}</h4>`;
        } else if (line.startsWith('- ') || line.startsWith('* ')) {
            html += `<li style="margin-left:20px; margin-bottom:4px;">${parseInlineMd(line.slice(2))}</li>`;
        } else if (line.trim() === '---') {
            html += `<hr style="border:none; border-top:1px solid var(--border-color); margin:12px 0;">`;
        } else if (line.trim().length > 0) {
            html += `<p style="margin-bottom:8px;">${parseInlineMd(line)}</p>`;
        }
    }
    return html;
}

function parseInlineMd(text) {
    let t = escapeHtml(text);
    t = t.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    t = t.replace(/\*(.*?)\*/g, '<em>$1</em>');
    t = t.replace(/`([^`]+)`/g, '<code style="background:var(--bg-subtle); padding:2px 4px; border-radius:3px; font-size:11px; border:1px solid var(--border-color);">$1</code>');
    return t;
}


// 4. Lazy Asynchronous Background Status Checker (HeidiSQL style, zero UI freeze)
function initLazyStatusChecker() {
    const envLinks = document.querySelectorAll('.env-nav-item');
    envLinks.forEach(link => {
        const envKey = link.dataset.envKey;
        const badge = document.getElementById(`badge-${envKey}`);
        if (!envKey || !badge) return;

        // Si es el activo ya cargó, para los demás consultamos suavemente en segundo plano
        if (link.classList.contains('active')) {
            badge.className = 'status-badge badge-sync';
            badge.textContent = 'ACTIVO';
            return;
        }

        fetch(`/?api_status=${encodeURIComponent(envKey)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'synced') {
                    badge.className = 'status-badge badge-sync';
                    badge.textContent = 'SYNC';
                } else if (data.status === 'behind') {
                    badge.className = 'status-badge badge-warn';
                    badge.textContent = `-${data.pending}`;
                } else if (data.status === 'conflict') {
                    badge.className = 'status-badge badge-danger';
                    badge.textContent = 'DRIFT';
                } else {
                    badge.className = 'status-badge badge-muted';
                    badge.textContent = 'OFF';
                }
            })
            .catch(() => {
                badge.className = 'status-badge badge-muted';
                badge.textContent = 'OFF';
            });
    });
}


// 5. Mobile & Responsive Sidebars / Drawers Management
function toggleMobileSidebar() {
    const sidebarLeft = document.getElementById('sidebarLeft');
    const sidebarRight = document.getElementById('sidebarRight');
    const backdrop = document.getElementById('sidebarMobileBackdrop');
    
    if (!sidebarLeft) return;

    // Si el inspector derecho estaba abierto, lo cerramos primero
    if (sidebarRight && sidebarRight.classList.contains('open')) {
        sidebarRight.classList.remove('open');
    }

    const isOpen = sidebarLeft.classList.toggle('open');
    if (backdrop) {
        backdrop.classList.toggle('open', isOpen);
    }
}

function toggleMobileInspector() {
    const sidebarLeft = document.getElementById('sidebarLeft');
    const sidebarRight = document.getElementById('sidebarRight');
    const backdrop = document.getElementById('sidebarMobileBackdrop');
    
    if (!sidebarRight) return;

    // Si el menú de entornos estaba abierto, lo cerramos primero
    if (sidebarLeft && sidebarLeft.classList.contains('open')) {
        sidebarLeft.classList.remove('open');
    }

    const isOpen = sidebarRight.classList.toggle('open');
    if (backdrop) {
        backdrop.classList.toggle('open', isOpen);
    }
}

function closeMobileSidebars() {
    const sidebarLeft = document.getElementById('sidebarLeft');
    const sidebarRight = document.getElementById('sidebarRight');
    const backdrop = document.getElementById('sidebarMobileBackdrop');

    if (sidebarLeft) sidebarLeft.classList.remove('open');
    if (sidebarRight) sidebarRight.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
}

// Cerrar paneles móviles con la tecla Escape
window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMobileSidebars();
    }
});

// Cerrar paneles al cambiar el tamaño de ventana a desktop
window.addEventListener('resize', () => {
    if (window.innerWidth > 960) {
        closeMobileSidebars();
    }
    updateConnectionWires();
});


// 6. View Mode Switching (Overview Matrix vs Timeline Table vs Flow Canvas)
function switchViewMode(mode) {
    const overviewView = document.getElementById('mirrorsOverviewView');
    const tableView = document.getElementById('timelineTableView');
    const flowView = document.getElementById('flowViewport');
    
    const overviewBtns = document.querySelectorAll('.view-btn[onclick*="overview"]');
    const tableBtns = document.querySelectorAll('.view-btn[onclick*="table"]');
    const canvasBtns = document.querySelectorAll('.view-btn[onclick*="canvas"]');

    // Desactivar todos los botones
    overviewBtns.forEach(b => b.classList.remove('active'));
    tableBtns.forEach(b => b.classList.remove('active'));
    canvasBtns.forEach(b => b.classList.remove('active'));

    // Ocultar todas las vistas
    if (overviewView) overviewView.style.display = 'none';
    if (tableView) tableView.style.display = 'none';
    if (flowView) flowView.style.display = 'none';

    if (mode === 'overview' && overviewView) {
        overviewView.style.display = 'block';
        overviewBtns.forEach(b => b.classList.add('active'));
        localStorage.setItem('syncdero-view-mode', 'overview');
    } else if (mode === 'canvas' && flowView) {
        flowView.style.display = 'block';
        canvasBtns.forEach(b => b.classList.add('active'));
        localStorage.setItem('syncdero-view-mode', 'canvas');
        setTimeout(updateConnectionWires, 50);
    } else {
        if (tableView) tableView.style.display = 'block';
        tableBtns.forEach(b => b.classList.add('active'));
        localStorage.setItem('syncdero-view-mode', 'table');
    }
}

function initViewMode() {
    const overviewView = document.getElementById('mirrorsOverviewView');
    const savedMode = localStorage.getItem('syncdero-view-mode');

    // Si hay una matriz de espejos y no hay preferencia guardada previa, usar 'overview'
    let targetMode = savedMode;
    if (!targetMode) {
        targetMode = overviewView ? 'overview' : 'table';
    } else if (targetMode === 'overview' && !overviewView) {
        targetMode = 'table';
    }

    switchViewMode(targetMode);
}

// 6.1 Dropdown de Usuario e Impersonación
function toggleUserDropdown(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('userDropdownMenu');
    if (dropdown) {
        dropdown.classList.toggle('open');
    }
}

function closeUserDropdown() {
    const dropdown = document.getElementById('userDropdownMenu');
    if (dropdown) {
        dropdown.classList.remove('open');
    }
}

// Cerrar dropdown al hacer click fuera
document.addEventListener('click', (e) => {
    const userContainer = document.querySelector('.user-profile-menu-container');
    if (userContainer && !userContainer.contains(e.target)) {
        closeUserDropdown();
    }
});


// 7. Developer Profile & Session Credentials Management
function initProfileAndSession() {
    initViewMode();

    // Cargar perfil guardado de sessionStorage si existe
    const savedName = sessionStorage.getItem('syncdero-dev-name');
    const savedEmail = sessionStorage.getItem('syncdero-dev-email');
    const savedPassword = sessionStorage.getItem('syncdero-dev-password');

    if (savedName) {
        const topName = document.getElementById('topbarDevName');
        const topAvatar = document.getElementById('topbarAvatarLetter');
        const inputName = document.getElementById('devProfileName');
        const hiddenName = document.getElementById('syncInputAuthorName');

        // Solo sobreescribir topbar si no se está impersonando activamente
        const isImpersonating = topName && topName.querySelector('small');
        if (topName && !isImpersonating) topName.textContent = savedName;
        if (topAvatar && !isImpersonating) topAvatar.textContent = savedName.charAt(0).toUpperCase();
        if (inputName) inputName.value = savedName;
        if (hiddenName) hiddenName.value = savedName;
    }

    if (savedEmail) {
        const inputEmail = document.getElementById('devProfileEmail');
        const hiddenEmail = document.getElementById('syncInputAuthorEmail');
        if (inputEmail) inputEmail.value = savedEmail;
        if (hiddenEmail) hiddenEmail.value = savedEmail;
    }

    if (savedPassword) {
        const inputPass = document.getElementById('devProfilePassword');
        if (inputPass) inputPass.value = savedPassword;

        // Auto-rellenar contraseña en el modal de Sync si está protegido
        const syncPass = document.querySelector('#syncModal input[type="password"]');
        if (syncPass) syncPass.value = savedPassword;
    }

    updateProfileSignaturePreview();
}

function updateProfileSignaturePreview() {
    const inputName = document.getElementById('devProfileName');
    const inputEmail = document.getElementById('devProfileEmail');
    const preview = document.getElementById('devProfileSignaturePreview');
    const syncPreview = document.getElementById('syncModalAuthorPreview');

    const name = inputName ? inputName.value.trim() : '';
    const email = inputEmail ? inputEmail.value.trim() : '';
    const sig = name ? (email ? `${name} <${email}>` : name) : 'Developer';

    if (preview) preview.textContent = sig;
    if (syncPreview) syncPreview.textContent = sig;
}

function openProfileModal() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        updateProfileSignaturePreview();
        modal.classList.add('open');
    }
}

function closeProfileModal() {
    smoothCloseModal('profileModal');
}

function saveDeveloperProfile() {
    const inputName = document.getElementById('devProfileName');
    const inputEmail = document.getElementById('devProfileEmail');
    const inputPass = document.getElementById('devProfilePassword');

    const name = inputName ? inputName.value.trim() : '';
    const email = inputEmail ? inputEmail.value.trim() : '';
    const pass = inputPass ? inputPass.value : '';

    if (name) {
        sessionStorage.setItem('syncdero-dev-name', name);
        const topName = document.getElementById('topbarDevName');
        const topAvatar = document.getElementById('topbarAvatarLetter');
        const hiddenName = document.getElementById('syncInputAuthorName');

        if (topName) topName.textContent = name;
        if (topAvatar) topAvatar.textContent = name.charAt(0).toUpperCase();
        if (hiddenName) hiddenName.value = name;
    }

    if (email) {
        sessionStorage.setItem('syncdero-dev-email', email);
        const hiddenEmail = document.getElementById('syncInputAuthorEmail');
        if (hiddenEmail) hiddenEmail.value = email;
    }

    if (pass !== '') {
        sessionStorage.setItem('syncdero-dev-password', pass);
        const syncPass = document.querySelector('#syncModal input[type="password"]');
        if (syncPass) syncPass.value = pass;
    }

    closeProfileModal();
}

// Manejo del menú overflow de acciones de la barra superior (Laptops compactas / Tablets / Celulares)
function toggleTopbarOverflow(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('topbarOverflowMenu');
    if (menu) {
        menu.classList.toggle('open');
    }
}

function closeTopbarOverflow() {
    const menu = document.getElementById('topbarOverflowMenu');
    if (menu) {
        menu.classList.remove('open');
    }
}

// Cerrar overflow al hacer click fuera
document.addEventListener('click', (e) => {
    const container = document.getElementById('topbarOverflowContainer');
    if (container && !container.contains(e.target)) {
        closeTopbarOverflow();
    }
});

// Interacciones y atajos globales inspirados en transitions-dev y minimalist-ui
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeTopbarOverflow();
        const openModal = document.querySelector('.modal-layer.open');
        if (openModal) {
            smoothCloseModal(openModal);
        }
    }
});

document.addEventListener('click', (e) => {
    if (e.target && e.target.classList.contains('modal-layer') && e.target.classList.contains('open')) {
        smoothCloseModal(e.target);
    }
});

