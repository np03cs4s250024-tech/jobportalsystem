/**
 * ── LOGIN ──
 */
async function handleLogin(e) {
    e.preventDefault();
    if (typeof clearAlert === 'function') clearAlert('alert-box');
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;

    if (!email || !password) {
        showAlert('alert-box', 'Please enter both email and password.', 'error');
        return;
    }

    setLoading('login-btn', true, 'Authenticating...');
    // The wrapper in main.js will now automatically inject "action: login" into the body
    const result = await apiPost('/api/auth.php?action=login', { email, password });
    setLoading('login-btn', false);

    if (result && result.success) {
        showAlert('alert-box', 'Login successful! Redirecting...', 'success');
        
        const paths = { 
            admin:    BASE + '/admin/dashboard.html',
            employer: BASE + '/employer/dashboard.html', 
            seeker:   BASE + '/seeker/dashboard.html' 
        };
        
        setTimeout(() => {
            const target = result.user && result.user.role ? paths[result.user.role] : BASE + '/index.html';
            window.location.href = target;
        }, 800);
    } else {
        showAlert('alert-box', result?.error || 'Invalid credentials or Server Error.', 'error');
    }
}

/**
 * ── REGISTER STEP 1: Send OTP ──
 */
async function handleSendOtp(e) {
    e.preventDefault();
    if (typeof clearAlert === 'function') clearAlert('alert-box');

    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('confirm-password')?.value;
    const role = document.getElementById('role')?.value || 'seeker';

    if (pass !== confirm) { 
        showAlert('alert-box', 'Passwords do not match.', 'error'); 
        return; 
    }

    setLoading('register-btn', true, 'Sending Code...');
    const result = await apiPost('/api/auth.php?action=send-otp', { name, email, password: pass, role });
    setLoading('register-btn', false);

    if (result && result.success) {
        document.getElementById('step-register').style.display = 'none';
        document.getElementById('step-otp').style.display = 'block';

        if (result.dev_otp) {
            const otpInput = document.getElementById('otp');
            if (otpInput) otpInput.value = result.dev_otp;
            showAlert('alert-box', `[DEV MODE] Use OTP: ${result.dev_otp}`, 'info');
        } else {
            showAlert('alert-box', 'Verification code sent to your email!', 'success');
        }
    } else {
        showAlert('alert-box', result?.error || 'Failed to send OTP.', 'error');
    }
}

/**
 * ── REGISTER STEP 2: Verify OTP ──
 */
async function handleVerifyOtp(e) {
    e.preventDefault();
    // Use the email from the registration step to ensure consistency
    const emailInput = document.getElementById('email');
    const email = emailInput ? emailInput.value.trim() : '';
    const otp = document.getElementById('otp')?.value.replace(/\s/g, ''); 

    if (!otp || otp.length < 4) {
        showAlert('alert-box', 'Enter the full verification code.', 'error');
        return;
    }

    setLoading('verify-btn', true, 'Verifying...');
    const result = await apiPost('/api/auth.php?action=verify-otp', { email, otp });
    setLoading('verify-btn', false);

    if (result && result.success) {
        showAlert('alert-box', 'Account verified! Redirecting to login...', 'success');
        setTimeout(() => window.location.href = 'login.html', 1500);
    } else {
        showAlert('alert-box', result?.error || 'Verification failed.', 'error');
    }
}

/**
 * ── FORGOT PASSWORD FLOW ──
 */
async function handleForgotPasswordRequest(e) {
    e.preventDefault();
    const email = document.getElementById('email').value.trim();
    
    if (!email) {
        showAlert('alert-box', 'Please enter your email address.', 'error');
        return;
    }

    setLoading('send-otp-btn', true, 'Checking email...');
    const result = await apiPost('/api/auth.php?action=forgot-password', { email });
    setLoading('send-otp-btn', false);

    if (result && result.success) {
        document.getElementById('step-request').style.display = 'none';
        document.getElementById('step-reset').style.display = 'block';
        
        // Auto-fill OTP if in dev mode
        if (result.dev_otp) {
            const otpField = document.getElementById('otp');
            if (otpField) otpField.value = result.dev_otp;
        }
        
        showAlert('alert-box', 'Reset code sent! Check your inbox.', 'success');
    } else {
        showAlert('alert-box', result?.error || 'Request failed.', 'error');
    }
}

/**
 * ── PASSWORD RESET FINAL ──
 */
async function handlePasswordResetFinal(e) {
    e.preventDefault();
    const email = document.getElementById('email').value.trim();
    const otp = document.getElementById('otp').value.trim();
    const new_password = document.getElementById('newpass').value;
    const confirm = document.getElementById('confirm').value;

    if (!otp) {
        showAlert('alert-box', 'Please enter the verification code.', 'error');
        return;
    }

    if (new_password.length < 6) {
        showAlert('alert-box', 'Password must be at least 6 characters.', 'error');
        return;
    }

    if (new_password !== confirm) {
        showAlert('alert-box', 'New passwords do not match.', 'error');
        return;
    }

    setLoading('reset-btn', true, 'Updating...');
    // Match the backend key 'new_password' precisely
    const result = await apiPost('/api/auth.php?action=reset-password', { email, otp, new_password });
    setLoading('reset-btn', false);

    if (result && result.success) {
        showAlert('alert-box', 'Password updated! Redirecting to login...', 'success');
        setTimeout(() => window.location.href = 'login.html', 1500);
    } else {
        showAlert('alert-box', result?.error || 'Reset failed.', 'error');
    }
}

function clearAlert(id) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '';
}