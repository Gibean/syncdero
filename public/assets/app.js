/**
 * SYNCDERO - Minimalist Modular & Draggable Node Flow
 * Notion / AI-Flow inspired clean canvas interaction
 */

document.addEventListener('DOMContentLoaded', () => {
    initThemeToggle();
    initDraggableNodes();
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
    if (!svg) return;

    // Conectar nodos en secuencia (Nodo 1 -> Nodo 2 -> Nodo 3)
    const nodes = Array.from(document.querySelectorAll('.flow-node'));
    let pathsHtml = '';

    for (let i = 0; i < nodes.length - 1; i++) {
        const fromNode = nodes[i];
        const toNode = nodes[i + 1];

        const fromRect = fromNode.getBoundingClientRect();
        const toRect = toNode.getBoundingClientRect();
        const viewportRect = document.getElementById('flowViewport').getBoundingClientRect();

        // Salida derecha del nodo origen
        const x1 = fromRect.right - viewportRect.left;
        const y1 = fromRect.top + (fromRect.height / 2) - viewportRect.top;

        // Entrada izquierda del nodo destino
        const x2 = toRect.left - viewportRect.left;
        const y2 = toRect.top + (toRect.height / 2) - viewportRect.top;

        // Control points para curva bezier suave
        const dx = Math.abs(x2 - x1) * 0.5;
        const cx1 = x1 + dx;
        const cy1 = y1;
        const cx2 = x2 - dx;
        const cy2 = y2;

        const isApplied = toNode.dataset.status === 'applied';
        const strokeClass = isApplied ? 'flow-wire solid' : 'flow-wire';

        pathsHtml += `<path d="M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${x2} ${y2}" class="${strokeClass}" />`;
    }

    svg.innerHTML = pathsHtml;
}

// Modales
function openSyncModal() {
    const modal = document.getElementById('syncModal');
    if (modal) modal.classList.add('open');
}

function closeSyncModal() {
    const modal = document.getElementById('syncModal');
    if (modal) modal.classList.remove('open');
}

function openServerModal() {
    const modal = document.getElementById('serverModal');
    if (modal) modal.classList.add('open');
}

function closeServerModal() {
    const modal = document.getElementById('serverModal');
    if (modal) modal.classList.remove('open');
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

