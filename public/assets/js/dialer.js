/* Online Web Dialer — JsSIP softphone with VU meter, mute, volume, timer. */
(function () {
    'use strict';

    var root = document.getElementById('os-dialer');
    if (!root || typeof JsSIP === 'undefined') {
        return;
    }

    var statusEl = document.getElementById('os-dialer-status');
    var linkEl = document.getElementById('os-dialer-link');
    var linkText = document.getElementById('os-dialer-link-text');
    var timerEl = document.getElementById('os-dialer-timer');
    var accountEl = document.getElementById('os-dialer-account');
    var cliEl = document.getElementById('os-dialer-cli');
    var numberEl = document.getElementById('os-dialer-number');
    var callBtn = document.getElementById('os-dialer-call');
    var hangBtn = document.getElementById('os-dialer-hangup');
    var backBtn = document.getElementById('os-dialer-back');
    var muteBtn = document.getElementById('os-dialer-mute');
    var muteIcon = document.getElementById('os-dialer-mute-icon');
    var volumeEl = document.getElementById('os-dialer-volume');
    var remoteAudio = document.getElementById('os-dialer-remote');
    var vuEl = document.getElementById('os-dialer-vu');
    var csrfEl = root.querySelector('input[name="_csrf"]');
    var credUrl = root.getAttribute('data-cred') || '';

    var ua = null;
    var session = null;
    var registered = false;
    var sipDomain = root.getAttribute('data-domain') || '';
    var muted = false;
    var localStream = null;
    var audioCtx = null;
    var analyser = null;
    var vuRaf = 0;
    var callStartedAt = 0;
    var timerIv = 0;

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    function setLink(state, label) {
        if (linkEl) {
            linkEl.setAttribute('data-state', state);
        }
        if (linkText) {
            linkText.textContent = label;
        }
    }

    function dialDigits(value) {
        return String(value || '').replace(/[^\d*#]/g, '');
    }

    function cliDigits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function formatTimer(sec) {
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function startTimer() {
        stopTimer();
        callStartedAt = Date.now();
        if (timerEl) {
            timerEl.hidden = false;
            timerEl.textContent = '00:00';
        }
        timerIv = window.setInterval(function () {
            if (!timerEl) { return; }
            timerEl.textContent = formatTimer(Math.floor((Date.now() - callStartedAt) / 1000));
        }, 250);
    }

    function stopTimer() {
        if (timerIv) {
            window.clearInterval(timerIv);
            timerIv = 0;
        }
        callStartedAt = 0;
        if (timerEl) {
            timerEl.hidden = true;
            timerEl.textContent = '00:00';
        }
    }

    function paintVu(level) {
        if (!vuEl) { return; }
        var bars = vuEl.querySelectorAll('span');
        var n = bars.length;
        var lit = Math.round(level * n);
        for (var i = 0; i < n; i++) {
            bars[i].classList.toggle('is-on', i < lit);
            bars[i].classList.toggle('is-hot', i < lit && i >= n - 2);
        }
    }

    function stopVu() {
        if (vuRaf) {
            window.cancelAnimationFrame(vuRaf);
            vuRaf = 0;
        }
        paintVu(0);
        if (localStream) {
            localStream.getTracks().forEach(function (t) { t.stop(); });
            localStream = null;
        }
        analyser = null;
        if (audioCtx) {
            try { audioCtx.close(); } catch (e) { /* ignore */ }
            audioCtx = null;
        }
    }

    function tickVu() {
        if (!analyser) { return; }
        var data = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) {
            var v = (data[i] - 128) / 128;
            sum += v * v;
        }
        var rms = Math.sqrt(sum / data.length);
        var level = muted ? 0 : Math.min(1, rms * 4.5);
        paintVu(level);
        vuRaf = window.requestAnimationFrame(tickVu);
    }

    function startVu(stream) {
        stopVu();
        localStream = stream;
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            var src = audioCtx.createMediaStreamSource(stream);
            analyser = audioCtx.createAnalyser();
            analyser.fftSize = 256;
            src.connect(analyser);
            tickVu();
        } catch (e) {
            paintVu(0);
        }
    }

    function applyMute() {
        var tracks = [];
        if (localStream) {
            tracks = tracks.concat(localStream.getAudioTracks());
        }
        if (session && session.connection) {
            session.connection.getSenders().forEach(function (sender) {
                if (sender.track && sender.track.kind === 'audio') {
                    tracks.push(sender.track);
                }
            });
        }
        tracks.forEach(function (t) { t.enabled = !muted; });
        if (muteBtn) {
            muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
            muteBtn.classList.toggle('is-muted', muted);
            muteBtn.title = muted ? 'Unmute microphone' : 'Mute microphone';
        }
        if (muteIcon) {
            muteIcon.className = muted ? 'bi bi-mic-mute-fill' : 'bi bi-mic-fill';
        }
        if (muted) {
            paintVu(0);
        }
    }

    function applyVolume() {
        if (remoteAudio && volumeEl) {
            remoteAudio.volume = Math.max(0, Math.min(1, Number(volumeEl.value) / 100));
        }
    }

    function attachRemote(pc) {
        if (!pc || !remoteAudio) { return; }
        var bind = function () {
            var streams = pc.getRemoteStreams ? pc.getRemoteStreams() : [];
            if (streams && streams[0]) {
                remoteAudio.srcObject = streams[0];
                applyVolume();
                remoteAudio.play().catch(function () { /* autoplay until gesture */ });
                return;
            }
            var receivers = pc.getReceivers ? pc.getReceivers() : [];
            var tracks = [];
            receivers.forEach(function (r) {
                if (r.track && r.track.kind === 'audio') {
                    tracks.push(r.track);
                }
            });
            if (tracks.length) {
                remoteAudio.srcObject = new MediaStream(tracks);
                applyVolume();
                remoteAudio.play().catch(function () { /* ignore */ });
            }
        };
        pc.addEventListener('track', bind);
        pc.addEventListener('addstream', bind);
        bind();
    }

    function callActive() {
        return !!(session && !session.isEnded());
    }

    function endCall() {
        if (session) {
            try { session.terminate(); } catch (e) { /* ignore */ }
        }
    }

    function stopUa() {
        registered = false;
        endCall();
        session = null;
        stopTimer();
        stopVu();
        if (remoteAudio) {
            remoteAudio.srcObject = null;
        }
        if (ua) {
            try { ua.stop(); } catch (e) { /* ignore */ }
            ua = null;
        }
        muted = false;
        applyMute();
        if (hangBtn) { hangBtn.disabled = true; }
        if (callBtn) { callBtn.disabled = false; }
        setLink('offline', 'Disconnected');
    }

    function startUa(cred) {
        stopUa();
        sipDomain = cred.domain || sipDomain || '';
        if (!cred.webrtc_enabled) {
            setStatus('WebRTC is not ready. Ask an administrator to Enable WebRTC under Settings.');
            setLink('offline', 'Disconnected');
            return;
        }
        if (!sipDomain) {
            setStatus('No SSL domain is set. Install a certificate under Settings → SSL.');
            setLink('offline', 'Disconnected');
            return;
        }
        setLink('connecting', 'Connecting');
        // nginx terminates WSS → plain WS to FreeSWITCH ws-binding. Via must say WS
        // (not WSS) or Sofia drops replies: "invalid transport SIP/2.0/WSS".
        var socket = new JsSIP.WebSocketInterface(cred.ws_url);
        socket.via_transport = 'WS';
        ua = new JsSIP.UA({
            sockets: [socket],
            uri: 'sip:' + cred.username + '@' + sipDomain,
            password: cred.password,
            authorization_user: cred.username,
            display_name: cred.username,
            register: true,
            register_expires: 300,
            session_timers: false,
            connection_recovery_min_interval: 2,
            connection_recovery_max_interval: 30
        });
        ua.on('connecting', function () {
            setStatus('Connecting to ' + sipDomain + '…');
            setLink('connecting', 'Connecting');
        });
        ua.on('connected', function () {
            setStatus('Registering ' + cred.username + '…');
            setLink('connecting', 'Connecting');
        });
        ua.on('registered', function () {
            registered = true;
            setStatus('Connected as ' + cred.username);
            setLink('online', 'Connected');
            navigator.mediaDevices.getUserMedia({ audio: true, video: false })
                .then(function (stream) { startVu(stream); })
                .catch(function () { paintVu(0); });
        });
        ua.on('unregistered', function () {
            registered = false;
            setStatus('Disconnected');
            setLink('offline', 'Disconnected');
            stopVu();
        });
        ua.on('registrationFailed', function (ev) {
            registered = false;
            var cause = ev && ev.cause ? ev.cause : 'failed';
            var code = ev && ev.response && ev.response.status_code ? (' (' + ev.response.status_code + ')') : '';
            setStatus('Registration failed: ' + cause + code);
            setLink('offline', 'Disconnected');
            stopVu();
        });
        ua.on('disconnected', function (ev) {
            if (!registered) {
                var cause = ev && ev.cause ? String(ev.cause) : '';
                setStatus(cause && cause !== 'error' ? ('Disconnected: ' + cause) : 'WebSocket disconnected');
                setLink('offline', 'Disconnected');
            }
        });
        ua.start();
    }

    function loadAccount() {
        if (!accountEl || !csrfEl) { return; }
        setStatus('Loading SIP user…');
        setLink('connecting', 'Connecting');
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
        if (callActive()) {
            try { session.sendDTMF(key); } catch (e) { /* ignore */ }
            return;
        }
        if (!numberEl) { return; }
        if (numberEl.value.length >= 32) { return; }
        numberEl.value += key;
    }

    function placeCall() {
        if (!ua || !registered || !numberEl) {
            setStatus('Connect a SIP user before calling');
            return;
        }
        if (callActive()) {
            return;
        }
        var dest = dialDigits(numberEl.value);
        if (dest === '') {
            setStatus('Enter a number');
            return;
        }
        numberEl.value = dest;
        var headers = [];
        var cli = cliDigits(cliEl ? cliEl.value : '');
        if (cli !== '') {
            headers.push('X-Ownswitch-Cli: ' + cli);
        }
        setStatus('Calling ' + dest + '…');
        setLink('calling', 'Calling');
        session = ua.call('sip:' + dest + '@' + sipDomain, {
            extraHeaders: headers,
            mediaConstraints: { audio: true, video: false },
            pcConfig: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] },
            rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false }
        });
        if (callBtn) { callBtn.disabled = true; }
        if (hangBtn) { hangBtn.disabled = false; }
        if (session.connection) {
            attachRemote(session.connection);
        }
        session.on('peerconnection', function (ev) {
            attachRemote(ev.peerconnection || session.connection);
        });
        session.on('progress', function () {
            setStatus('Ringing ' + dest + '…');
            setLink('calling', 'Ringing');
        });
        session.on('accepted', function () {
            setStatus('In call with ' + dest);
            setLink('online', 'Connected');
            startTimer();
            applyMute();
            applyVolume();
        });
        session.on('confirmed', function () {
            setStatus('In call with ' + dest);
            setLink('online', 'Connected');
            if (!callStartedAt) {
                startTimer();
            }
            applyMute();
            applyVolume();
        });
        session.on('ended', function () {
            session = null;
            stopTimer();
            if (callBtn) { callBtn.disabled = false; }
            if (hangBtn) { hangBtn.disabled = true; }
            setStatus(registered ? 'Connected as ' + (accountEl.options[accountEl.selectedIndex].text || '') : 'Call ended');
            setLink(registered ? 'online' : 'offline', registered ? 'Connected' : 'Disconnected');
        });
        session.on('failed', function (ev) {
            session = null;
            stopTimer();
            if (callBtn) { callBtn.disabled = false; }
            if (hangBtn) { hangBtn.disabled = true; }
            var cause = ev && ev.cause ? ev.cause : 'failed';
            setStatus('Call failed: ' + cause);
            setLink(registered ? 'online' : 'offline', registered ? 'Connected' : 'Disconnected');
        });
    }

    root.querySelectorAll('.os-phone-key').forEach(function (btn) {
        btn.addEventListener('click', function () {
            appendKey(btn.getAttribute('data-key') || '');
        });
    });
    if (backBtn && numberEl) {
        backBtn.addEventListener('click', function () {
            if (callActive()) { return; }
            numberEl.value = numberEl.value.slice(0, -1);
            numberEl.focus();
        });
    }
    if (callBtn) { callBtn.addEventListener('click', placeCall); }
    if (hangBtn) { hangBtn.addEventListener('click', endCall); }
    if (muteBtn) {
        muteBtn.addEventListener('click', function () {
            muted = !muted;
            applyMute();
        });
    }
    if (volumeEl) {
        volumeEl.addEventListener('input', applyVolume);
        applyVolume();
    }
    if (accountEl) { accountEl.addEventListener('change', loadAccount); }

    if (numberEl) {
        numberEl.addEventListener('paste', function (ev) {
            ev.preventDefault();
            var text = '';
            if (ev.clipboardData) {
                text = ev.clipboardData.getData('text') || '';
            }
            var cleaned = dialDigits(text);
            if (!cleaned) { return; }
            var start = numberEl.selectionStart || 0;
            var end = numberEl.selectionEnd || 0;
            var next = (numberEl.value.slice(0, start) + cleaned + numberEl.value.slice(end)).slice(0, 32);
            numberEl.value = dialDigits(next);
        });
        numberEl.addEventListener('input', function () {
            var pos = numberEl.selectionStart || numberEl.value.length;
            var before = numberEl.value.slice(0, pos);
            var cleaned = dialDigits(numberEl.value);
            numberEl.value = cleaned.slice(0, 32);
            var newPos = dialDigits(before).length;
            numberEl.setSelectionRange(newPos, newPos);
        });
    }

    document.addEventListener('keydown', function (ev) {
        var tag = (ev.target && ev.target.tagName) || '';
        if (ev.key === 'Escape') {
            if (callActive()) {
                ev.preventDefault();
                endCall();
            }
            return;
        }
        if (ev.key === 'Enter') {
            if (tag === 'TEXTAREA') { return; }
            if (callActive()) { return; }
            ev.preventDefault();
            placeCall();
            return;
        }
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
            return;
        }
        if (ev.key >= '0' && ev.key <= '9' || ev.key === '*' || ev.key === '#') {
            appendKey(ev.key);
        }
    });

    loadAccount();
}());
