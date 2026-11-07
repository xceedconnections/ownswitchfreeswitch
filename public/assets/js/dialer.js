/* Online Web Dialer. JsSIP registers a password SIP user and places one call. */
(function () {
    'use strict';

    var root = document.getElementById('os-dialer');
    if (!root || typeof JsSIP === 'undefined') {
        return;
    }

    var statusEl = document.getElementById('os-dialer-status');
    var accountEl = document.getElementById('os-dialer-account');
    var cliEl = document.getElementById('os-dialer-cli');
    var numberEl = document.getElementById('os-dialer-number');
    var callBtn = document.getElementById('os-dialer-call');
    var hangBtn = document.getElementById('os-dialer-hangup');
    var backBtn = document.getElementById('os-dialer-back');
    var csrfEl = root.querySelector('input[name="_csrf"]');
    var credUrl = root.getAttribute('data-cred') || '';
    var ua = null;
    var session = null;
    var registered = false;
    var sipDomain = '';

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    function digits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function stopUa() {
        registered = false;
        if (session) {
            try { session.terminate(); } catch (e) { /* already gone */ }
            session = null;
        }
        if (ua) {
            try { ua.stop(); } catch (e) { /* already stopped */ }
            ua = null;
        }
        if (hangBtn) { hangBtn.disabled = true; }
        if (callBtn) { callBtn.disabled = false; }
    }

    function startUa(cred) {
        stopUa();
        sipDomain = cred.domain || '';
        if (!cred.webrtc_enabled) {
            setStatus('WebRTC is not enabled. Ask an administrator to open Settings → WebRTC.');
            return;
        }
        var socket = new JsSIP.WebSocketInterface(cred.ws_url);
        ua = new JsSIP.UA({
            sockets: [socket],
            uri: 'sip:' + cred.username + '@' + cred.domain,
            password: cred.password,
            authorization_user: cred.username,
            register: true,
            register_expires: 300,
            session_timers: false,
            connection_recovery_min_interval: 2,
            connection_recovery_max_interval: 30
        });
        ua.on('connecting', function () { setStatus('Connecting…'); });
        ua.on('connected', function () { setStatus('Registering ' + cred.username + '…'); });
        ua.on('registered', function () {
            registered = true;
            setStatus('Registered as ' + cred.username);
        });
        ua.on('unregistered', function () {
            registered = false;
            setStatus('Not registered');
        });
        ua.on('registrationFailed', function (ev) {
            registered = false;
            var cause = ev && ev.cause ? ev.cause : 'failed';
            setStatus('Registration failed: ' + cause);
        });
        ua.on('disconnected', function () {
            if (!registered) {
                setStatus('WebSocket disconnected');
            }
        });
        ua.start();
    }

    function loadAccount() {
        if (!accountEl || !csrfEl) { return; }
        setStatus('Loading SIP user…');
        var body = new FormData();
        body.append('_csrf', csrfEl.value);
        body.append('sip_account_id', accountEl.value);
        fetch(credUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    stopUa();
                    setStatus((data && data.error) || 'Cannot use this SIP user');
                    return;
                }
                startUa(data);
            })
            .catch(function () {
                stopUa();
                setStatus('Could not load the SIP user');
            });
    }

    function appendKey(key) {
        if (session && session.connection) {
            try { session.sendDTMF(key); } catch (e) { /* ignore */ }
            return;
        }
        if (!numberEl) { return; }
        if (numberEl.value.length >= 32) { return; }
        numberEl.value += key;
    }

    function placeCall() {
        if (!ua || !registered || !numberEl) {
            setStatus('Register a SIP user before calling');
            return;
        }
        var dest = digits(numberEl.value);
        if (dest === '') {
            setStatus('Enter a number');
            return;
        }
        var domain = sipDomain;
        var headers = [];
        var cli = digits(cliEl ? cliEl.value : '');
        if (cli !== '') {
            headers.push('X-Ownswitch-Cli: ' + cli);
        }
        setStatus('Calling ' + dest + '…');
        session = ua.call('sip:' + dest + '@' + domain, {
            extraHeaders: headers,
            mediaConstraints: { audio: true, video: false },
            pcConfig: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] },
            rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false }
        });
        if (callBtn) { callBtn.disabled = true; }
        if (hangBtn) { hangBtn.disabled = false; }
        session.on('progress', function () { setStatus('Ringing ' + dest + '…'); });
        session.on('accepted', function () { setStatus('In call with ' + dest); });
        session.on('confirmed', function () { setStatus('In call with ' + dest); });
        session.on('ended', function () {
            session = null;
            if (callBtn) { callBtn.disabled = false; }
            if (hangBtn) { hangBtn.disabled = true; }
            setStatus(registered ? 'Registered' : 'Call ended');
        });
        session.on('failed', function (ev) {
            session = null;
            if (callBtn) { callBtn.disabled = false; }
            if (hangBtn) { hangBtn.disabled = true; }
            var cause = ev && ev.cause ? ev.cause : 'failed';
            setStatus('Call failed: ' + cause);
        });
    }

    root.querySelectorAll('.os-dialer-key').forEach(function (btn) {
        btn.addEventListener('click', function () {
            appendKey(btn.getAttribute('data-key') || '');
        });
    });
    if (backBtn && numberEl) {
        backBtn.addEventListener('click', function () {
            if (session) { return; }
            numberEl.value = numberEl.value.slice(0, -1);
        });
    }
    if (callBtn) { callBtn.addEventListener('click', placeCall); }
    if (hangBtn) {
        hangBtn.addEventListener('click', function () {
            if (session) {
                try { session.terminate(); } catch (e) { /* ignore */ }
            }
        });
    }
    if (accountEl) { accountEl.addEventListener('change', loadAccount); }
    loadAccount();
}());
