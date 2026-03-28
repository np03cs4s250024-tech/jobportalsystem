/**
 * ── 1. CONFIGURATION & PATHING ──
 */
const getBase = () => {
    const { hostname, origin, pathname } = window.location;
    // If on localhost, we likely have a subfolder (e.g., /my-project/)
    if (hostname === 'localhost' || hostname === '127.0.0.1') {
        const segments = pathname.split('/');
        return `${origin}/${segments[1]}`;
    }
    return origin;
};

const BASE = getBase();

/**
 * ── 2. THE FETCH WRAPPER ──
 */
async function _request(method, endpoint, data = null) {
    // Ensure endpoint starts with a slash
    const path = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
    const url = new URL(BASE + path);

    // Extract 'action' from URL query if present and move to data body
    // This is helpful if your PHP script expects 'action' in the JSON body
    if (data && url.searchParams.has('action')) {
        data.action = url.searchParams.get('action');
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000); // 8s timeout

    const opts = {
        method,
        credentials: 'include',
        signal: controller.signal,
        headers: { 
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
    };

    if (data && method !== 'GET') {
        opts.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(url.toString(), opts);
        clearTimeout(timeoutId);

        // Handle Session Expiry (401 Unauthorized)
        if (response.status === 401) {
            window.location.href = `${BASE}/auth/login.html?expired=1`;
            return;
        }

        const text = await response.text();
        
        try {
            const json = JSON.parse(text);
            return response.ok ? json : { success: false, ...json };
        } catch (e) {
            console.group('--- SERVER RESPONSE ERROR ---');
            console.error('URL:', url.toString());
            console.error('Status:', response.status);
            console.error('Raw Output:', text);
            console.groupEnd();
            return { success: false, error: 'Server returned invalid JSON. Check PHP logs.' };
        }

    } catch (e) {
        if (e.name === 'AbortError') {
            return { success: false, error: 'Request timed out. Is the server slow?' };
        }
        console.error('Network/Connection Error:', e);
        return { success: false, error: 'Cannot connect to server. Ensure XAMPP/Apache is running.' };
    }
}

const apiGet  = ep => _request('GET', ep);
const apiPost = (ep, d) => _request('POST', ep, d);

/**
 * ── 3. UI HELPERS & GLOBAL ACTIONS ──
 */
function showAlert(id, message, type = 'success') {
    const el = document.getElementById(id);
    if (!el) return;

    const themes = {
        success: { bg: '#d4edda', text: '#155724', border: '#c3e6cb', icon: '✓' },
        error:   { bg: '#f8d7da', text: '#721c24', border: '#f5c6cb', icon: '✕' },
        info:    { bg: '#e7f3ff', text: '#0a66c2', border: '#b3d7ff', icon: 'ℹ' }
    };
    
    const cfg = themes[type] || themes.info;
    
    el.innerHTML = `
        <div style="padding:12px; border-radius:6px; margin-bottom:15px; background:${cfg.bg}; color:${cfg.text}; border:1px solid ${cfg.border}; font-size:14px; display:flex; align-items:center; gap:10px; font-family: system-ui, -apple-system, sans-serif; animation: fadeIn 0.3s ease;">
            <strong style="font-size:18px;">${cfg.icon}</strong>
            <span style="flex:1;">${message}</span>
        </div>
    `;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function setLoading(btnId, isLoading, label = 'Processing...') {
    const btn = document.getElementById(btnId);
    if (!btn) return;

    if (isLoading) {
        btn.dataset.orig = btn.innerHTML;
        btn.disabled = true;
        // Adding a small spinner placeholder if you use FontAwesome
        btn.innerHTML = `<span class="spinner"></span> ${label}`;
    } else {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.orig || 'Submit';
    }
}

async function logout() {
    try {
        // We call the API, but redirect regardless of result to ensure the user "leaves" the app
        await apiPost('/api/auth.php?action=logout', {});
    } finally {
        // Clear any local storage if you use it
        localStorage.clear();
        window.location.href = `${BASE}/auth/login.html`;
    }
}