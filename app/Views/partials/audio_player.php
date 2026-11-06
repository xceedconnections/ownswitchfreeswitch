<style>
.os-audio-dock{position:fixed;left:0;right:0;bottom:0;z-index:1030;background:#fff;border-top:1px solid #e6e8ee;box-shadow:0 -6px 20px rgba(15,23,42,.08);padding:.65rem 1rem}
.os-audio-dock[hidden]{display:none !important}
.os-audio-dock-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:.75rem}
.os-audio-dock audio{flex:1;min-width:0;height:40px}
.os-audio-dock-label{min-width:0;max-width:18rem;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
<div id="os-player" class="os-audio-dock" hidden>
    <div class="os-audio-dock-inner">
        <i class="bi bi-play-circle"></i>
        <span class="os-audio-dock-label" id="os-player-label">Recording</span>
        <audio id="os-player-audio" controls preload="none"></audio>
        <a id="os-player-dl" class="btn btn-outline-secondary btn-sm" href="#"><i class="bi bi-download me-1"></i>Download</a>
    </div>
</div>
<script>
(function () {
    var bar = document.getElementById('os-player');
    var audio = document.getElementById('os-player-audio');
    var label = document.getElementById('os-player-label');
    var dl = document.getElementById('os-player-dl');
    if (!bar || !audio || !label || !dl) { return; }
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-os-play]') : null;
        if (!btn) { return; }
        ev.preventDefault();
        var src = btn.getAttribute('data-src') || '';
        var down = btn.getAttribute('data-download') || src;
        var text = btn.getAttribute('data-label') || 'Recording';
        if (!src) { return; }
        bar.hidden = false;
        document.body.style.paddingBottom = '4.5rem';
        label.textContent = text;
        dl.href = down;
        if (audio.getAttribute('src') !== src) {
            audio.src = src;
        }
        var play = audio.play();
        if (play && play.catch) { play.catch(function () {}); }
    });
})();
</script>
